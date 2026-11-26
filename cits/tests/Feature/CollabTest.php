<?php

use App\Exceptions\BusinessException;
use App\Models\LookupItem;
use App\Models\Project;
use App\Models\User;
use App\Services\Collab\DependencyService;
use App\Services\Collab\NeedinfoService;
use App\Services\Collab\SavedQueryService;
use App\Services\Collab\SimilarService;
use App\Services\Collab\TagService;
use App\Services\Collab\WatchService;
use App\Services\IssueService;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// needinfo, watch, duplicate hint, saved queries, tags, dependencies (docs/bugzilla-inspired-suggestions.md)

function clUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@cl.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'CL', 'name' => 'CL', 'ticket_prefix' => 'CL']);
    $this->client = clUser('clc', 'client', ['client_pm'], $this->p);
    $this->client2 = clUser('clc2', 'client', ['client_pm'], $this->p);
    $this->pm = clUser('clpm', 'internal', ['internal_pm'], $this->p);
    $this->dev = clUser('cldev', 'internal', ['dev'], $this->p);
    $this->tester = clUser('cltest', 'internal', ['test'], $this->p);
    $this->ts = app(TicketService::class);
    $this->is = app(IssueService::class);
    $this->s = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->ticket = fn (string $title = '登入按鈕沒有反應') => $this->ts->create($this->client, $this->p->id, ['ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => $title, 'content_html' => '<p>x</p>']);
    $this->issue = fn (string $title = 'issue') => $this->is->createInternal($this->pm, $this->p->id, 'pm', ['title' => $title, 'content_html' => '<p>x</p>']);
    $this->notes = fn (User $u) => DB::table('user_notifications')->where('user_id', $u->id)->orderBy('id')->get();
});

it('needinfo: PM asks, client sees it, a client comment answers it, PM sees the answer once', function () {
    $t = ($this->ticket)();
    $this->ts->accept($this->pm, $t->id, $this->p->id);
    $svc = app(NeedinfoService::class);
    $status = $t->fresh()->status;

    ($this->s)($this->pm)->post(route('collab.needinfo.request', $t->id), ['note' => '請提供錯誤畫面截圖'])->assertSessionHas('ok');
    expect($t->fresh()->status)->toBe($status); // flag only, no status change
    expect(($this->notes)($this->client)->last()->url)->toBe("/tickets/{$t->id}");
    expect(fn () => $svc->request($this->pm, $t->id, $this->p->id, '再一次'))->toThrow(BusinessException::class, '已經在等待');
    ($this->s)($this->client)->get(route('tickets.show', $t->id))->assertOk()->assertSee('需要您補充資料')->assertSee('請提供錯誤畫面截圖');
    ($this->s)($this->pm)->get(route('pm.index', ['ni' => 'waiting', 'oa' => 0]))->assertOk()->assertSee($t->ticket_no)->assertSee('等客戶補充');
    ($this->s)($this->client)->get(route('tickets.index', ['ni' => 'waiting']))->assertOk()->assertSee('需要補充');
    // a client cannot raise needinfo
    ($this->s)($this->client)->post(route('collab.needinfo.request', $t->id), ['note' => 'x'])->assertSessionHas('err');

    $this->ts->addComment($this->client, $t->id, $this->p->id, 'supplement', '<p>附上截圖</p>');
    expect($svc->open($t->id))->toBeNull();
    expect($svc->states([$t->id]))->toBe([$t->id => 'answered']);
    expect(($this->notes)($this->pm)->pluck('title'))->toContain("{$t->ticket_no} 客戶已補充資料");
    ($this->s)($this->pm)->get(route('pm.tickets.show', $t->id))->assertOk()->assertSee('客戶已補充');
    expect($svc->states([$t->id]))->toBe([]); // seen by the PM
});

it('needinfo: withdraw, and the daily reminder fires once for old requests', function () {
    $t = ($this->ticket)();
    $svc = app(NeedinfoService::class);
    $svc->request($this->pm, $t->id, $this->p->id, '請補 log');
    $svc->withdraw($this->pm, $t->id, $this->p->id);
    expect($svc->open($t->id))->toBeNull();
    $this->ts->addComment($this->client, $t->id, $this->p->id, 'supplement', '<p>x</p>');
    expect($svc->history($t->id)->first()->resolution)->toBe('withdrawn'); // a later comment does not rewrite it

    $svc->request($this->pm, $t->id, $this->p->id, '請補重現步驟');
    DB::table('ticket_needinfo')->whereNull('resolved_at')->update(['requested_at' => now()->subDays(5)]);
    expect($svc->remind())->toBe(1)->and($svc->remind())->toBe(0);
    expect(($this->notes)($this->client)->pluck('title')->filter(fn ($x) => str_contains($x, '仍在等待'))->count())->toBe(1);
    $this->artisan('cits:needinfo-remind')->assertSuccessful();
});

it('watch: watchers get the link of their side, never their own actions, and clients never watch issues', function () {
    $t = ($this->ticket)();
    $w = app(WatchService::class);
    ($this->s)($this->dev)->post(route('collab.tickets.watch', $t->id), ['on' => 1])->assertSessionHas('ok');
    ($this->s)($this->client2)->post(route('collab.tickets.watch', $t->id), ['on' => 1])->assertSessionHas('ok');
    expect($w->watchingTicket($this->dev, $t->id))->toBeTrue();

    $this->ts->addComment($this->client, $t->id, $this->p->id, 'supplement', '<p>more</p>');
    expect(($this->notes)($this->dev)->last()->url)->toBe("/pm/tickets/{$t->id}");
    expect(($this->notes)($this->client2)->last()->url)->toBe("/tickets/{$t->id}");
    expect(($this->notes)($this->client)->pluck('title'))->not->toContain("{$t->ticket_no} 有新留言"); // own comment

    // removed from the project -> no more notifications
    $this->p->members()->detach($this->client2->id);
    $before = ($this->notes)($this->client2)->count();
    $this->ts->accept($this->pm, $t->id, $this->p->id);
    $this->ts->addComment($this->pm, $t->id, $this->p->id, $this->ts->internalReplyKind(), '<p>reply</p>');
    expect(($this->notes)($this->client2)->count())->toBe($before);

    $i = ($this->issue)();
    ($this->s)($this->client)->post(route('collab.issues.watch', $i->id), ['on' => 1])->assertSessionHas('err');
    ($this->s)($this->tester)->post(route('collab.issues.watch', $i->id), ['on' => 1])->assertSessionHas('ok');
    $this->is->addComment($this->dev, $i->id, $this->p->id, '<p>internal</p>');
    expect(($this->notes)($this->tester)->last()->url)->toBe("/issues/{$i->id}");
    ($this->s)($this->tester)->post(route('collab.issues.watch', $i->id), ['on' => 0]);
    expect($w->watchingIssue($this->tester, $i->id))->toBeFalse();
});

it('similar: client search only returns client tickets; internal search adds issues; cancelled tickets left out', function () {
    $a = ($this->ticket)('登入按鈕沒有反應');
    ($this->ticket)('報表匯出很慢');
    $c = ($this->ticket)('登入頁面按鈕無效');
    $this->ts->cancel($this->client, $c->id, $this->p->id, null);
    ($this->issue)('登入按鈕失效（內部）');
    $svc = app(SimilarService::class);
    expect($svc->tokens('登入按鈕 ABC-12'))->toContain('登入')->toContain('按鈕')->toContain('abc-12');

    $res = ($this->s)($this->client)->getJson(route('collab.similar.tickets', ['title' => '登入按鈕不能按']))->assertOk()->json('items');
    expect(collect($res)->pluck('no')->all())->toBe([$a->ticket_no]);
    expect(collect($res)->pluck('type')->unique()->all())->toBe(['ticket']);
    ($this->s)($this->client)->getJson(route('collab.similar.internal', ['title' => '登入按鈕']))->assertForbidden();

    $res = ($this->s)($this->pm)->getJson(route('collab.similar.internal', ['title' => '登入按鈕不能按']))->assertOk()->json('items');
    expect(collect($res)->pluck('type')->sort()->values()->all())->toBe(['issue', 'ticket']);
});

it('saved queries: only whitelisted keys, same name overwrites, owner only, side checked', function () {
    $svc = app(SavedQueryService::class);
    ($this->s)($this->pm)->post(route('collab.queries.store'), ['scope' => 'pm', 'name' => '本週', 'qs' => 'stage=DEV&q=登入&evil=1&tags[]=ui'])
        ->assertRedirect(route('pm.index', ['stage' => 'DEV', 'q' => '登入', 'tags' => ['ui']]));
    $q = $svc->list($this->pm, $this->p->id, 'pm')->first();
    expect($q->params)->toBe(['stage' => 'DEV', 'q' => '登入', 'tags' => ['ui']]);
    $svc->save($this->pm, $this->p->id, 'pm', '本週', ['stage' => 'TEST']);
    expect($svc->list($this->pm, $this->p->id, 'pm'))->toHaveCount(1)->and($svc->list($this->pm, $this->p->id, 'pm')->first()->params)->toBe(['stage' => 'TEST']);
    ($this->s)($this->pm)->get(route('pm.index'))->assertOk()->assertSee('本週');

    expect(fn () => $svc->delete($this->dev, $this->p->id, $q->id))->toThrow(BusinessException::class);
    expect(fn () => $svc->save($this->client, $this->p->id, 'pm', 'x', []))->toThrow(BusinessException::class);
    $svc->save($this->client, $this->p->id, 'client', '我的', ['mine' => '1', 'ni' => 'waiting']);
    ($this->s)($this->client)->get(route('tickets.index'))->assertOk()->assertSee('我的');
    $svc->delete($this->pm, $this->p->id, $q->id);
    expect($svc->list($this->pm, $this->p->id, 'pm'))->toHaveCount(0);
});

it('tags: controlled list, kept when disabled, filter on the PM board, internal only', function () {
    LookupItem::create(['category' => 'issue_tag', 'code' => 'ui', 'name' => '畫面', 'sort' => 10, 'is_active' => true]);
    LookupItem::create(['category' => 'issue_tag', 'code' => 'perf', 'name' => '效能', 'sort' => 20, 'is_active' => true]);
    $svc = app(TagService::class);
    $i1 = ($this->issue)('tagged one');
    $i2 = ($this->issue)('plain two');
    ($this->s)($this->dev)->post(route('collab.issues.tags', $i1->id), ['tags' => ['ui', 'perf']])->assertSessionHas('ok');
    expect($svc->of($i1->id))->toBe(['perf', 'ui']);
    expect(fn () => $svc->set($this->dev, $i1, ['nope']))->toThrow(BusinessException::class);
    LookupItem::where(['category' => 'issue_tag', 'code' => 'perf'])->update(['is_active' => false]);
    $svc->set($this->dev, $i1->fresh(), ['ui', 'perf']); // disabled but already there: allowed
    expect(fn () => $svc->set($this->dev, $i2, ['perf']))->toThrow(BusinessException::class);

    ($this->s)($this->pm)->get(route('pm.index', ['tags' => ['ui']]))->assertOk()->assertSee('tagged one')->assertDontSee('plain two');
    ($this->s)($this->client)->post(route('collab.issues.tags', $i1->id), ['tags' => []])->assertSessionHas('err');
    expect($this->is->timeline($i1->fresh())->pluck('label'))->toContain('變更標籤');
});

it('dependencies: blocking upstream stops dispatch to DEV until done; no cycles; Test new-found issues cannot wait', function () {
    $deps = app(DependencyService::class);
    $a = ($this->issue)('A downstream');
    $b = ($this->issue)('B upstream');
    ($this->s)($this->pm)->post(route('collab.deps.store', $a->id), ['issue_no' => $b->issue_no, 'kind' => 'blocks'])->assertSessionHas('ok');
    expect($deps->blockers([$a->id]))->toBe([$a->id => [$b->issue_no]]);
    expect(fn () => $deps->add($this->pm, $b->fresh(), $a->issue_no, 'blocks'))->toThrow(BusinessException::class, '已經有相依關係');
    $c = ($this->issue)('C');
    $deps->add($this->pm, $b->fresh(), $c->issue_no, 'blocks'); // A -> B -> C
    expect(fn () => $deps->add($this->pm, $c->fresh(), $a->issue_no, 'blocks'))->toThrow(BusinessException::class, '循環');
    $deps->add($this->pm, $c->fresh(), $a->issue_no, 'related'); // related is never checked

    expect(fn () => $this->is->transition($this->pm, $a->id, $this->p->id, 'dev_todo', ['assignee_dev_id' => $this->dev->id]))
        ->toThrow(BusinessException::class, '需等待');
    ($this->s)($this->pm)->get(route('pm.index'))->assertOk()->assertSee('等待 1 張');
    ($this->s)($this->pm)->get(route('issues.show', $a->id))->assertOk()->assertSee('需等待 '.$b->issue_no);

    $this->is->transition($this->pm, $b->id, $this->p->id, 'cancelled', ['cancel_reason' => '不做']); // excluded -> no longer blocks
    $this->is->transition($this->pm, $a->id, $this->p->id, 'dev_todo', ['assignee_dev_id' => $this->dev->id]);
    expect($a->fresh()->status)->toBe('dev_todo');

    $found = app(\App\Services\Test\TestService::class)->createIssue($this->tester, $this->p->id, ['title' => 'found', 'content_html' => '<p>x</p>', 'assignee_dev_id' => $this->dev->id], null);
    expect(fn () => $deps->add($this->pm, $found, $c->issue_no, 'blocks'))->toThrow(BusinessException::class, '新發現');
    $d = $deps->forIssue($a->fresh())['upstream']->first();
    ($this->s)($this->pm)->delete(route('collab.deps.destroy', [$a->id, $d->id]))->assertSessionHas('ok');
    expect($deps->forIssue($a->fresh())['upstream'])->toHaveCount(0);
    ($this->s)($this->client)->post(route('collab.deps.store', $a->id), ['issue_no' => $c->issue_no, 'kind' => 'blocks'])->assertSessionHas('err');
});
