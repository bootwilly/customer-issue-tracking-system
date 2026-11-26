<?php

use App\Exceptions\BusinessException;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\Client\ClientProgressService;
use App\Services\IssueService;
use App\Services\TicketService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// client progress, PM sees client-closed tickets, one package holds many fixes

function ppUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@pp.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'PP', 'name' => 'PP', 'ticket_prefix' => 'PP']);
    $this->client = ppUser('ppc', 'client', ['client_pm'], $this->p);
    $this->pm = ppUser('pppm', 'internal', ['internal_pm'], $this->p);
    $this->dev = ppUser('ppdev', 'internal', ['dev'], $this->p);
    $this->tester = ppUser('pptest', 'internal', ['test'], $this->p);
    $this->ts = app(TicketService::class);
    $this->is = app(IssueService::class);
    $this->s = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->ticket = fn (string $title = '登入按鈕沒有反應') => $this->ts->create($this->client, $this->p->id, ['ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => $title, 'content_html' => '<p>x</p>']);
    $this->toDev = function ($i) {
        $this->is->transition($this->pm, $i->id, $this->p->id, 'dev_todo', ['assignee_dev_id' => $this->dev->id]);
        $this->is->transition($this->dev, $i->id, $this->p->id, 'dev_doing', ['planned_due' => '2026-12-01']);
    };
});

it('client sees processing progress driven by the slowest linked issue, without internal numbers', function () {
    $svc = app(ClientProgressService::class);
    $t = ($this->ticket)();
    expect($svc->forTicket($t->fresh())['label'])->toBe('已提交');

    $this->ts->accept($this->pm, $t->id, $this->p->id);
    expect($svc->forTicket($t->fresh())['label'])->toBe('已接收・評估中');

    $a = $this->is->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => 'A', 'content_html' => '<p>x</p>']);
    $b = $this->is->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => 'B', 'content_html' => '<p>x</p>']);
    ($this->toDev)($a);
    expect($svc->forTicket($t->fresh())['label'])->toBe('已接收・評估中'); // B still in PM

    $this->is->transition($this->pm, $b->id, $this->p->id, 'wont_fix', ['cancel_reason' => 'dup', 'client_note' => '重複']);
    expect($svc->forTicket($t->fresh())['label'])->toBe('修正中'); // excluded issues are ignored
    $pkg = $this->is->createPackage($this->dev, $this->p->id, 'v1');
    $this->is->transition($this->dev, $a->id, $this->p->id, 'test_pending', ['package_id' => $pkg, 'assignee_test_id' => $this->tester->id]);
    expect($svc->forTicket($t->fresh())['label'])->toBe('測試中');

    $this->is->transition($this->tester, $a->id, $this->p->id, 'test_doing');
    $this->is->transition($this->tester, $a->id, $this->p->id, 'release_pending', ['release_planned_at' => '2026-12-05']);
    $p = $svc->forTicket($t->fresh());
    expect($p['label'])->toBe('待更新正式環境')->and($p['eta'])->toBe('2026-12-05');

    $page = ($this->s)($this->client)->get(route('tickets.show', $t->id))->assertOk()->assertSee('處理進度')->assertSee('待更新正式環境')->assertSee('2026-12-05');
    $page->assertDontSeeText($a->issue_no);
    ($this->s)($this->client)->get(route('tickets.index'))->assertOk()->assertSee('待更新正式環境');
});

it('cancelled tickets show no progress', function () {
    $t = ($this->ticket)();
    $this->ts->cancel($this->client, $t->id, $this->p->id, null);
    expect(app(ClientProgressService::class)->forTicket($t->fresh()))->toBeNull();
});

it('PM is notified when the client closes, and can list closed tickets with their status on issue rows', function () {
    $t = ($this->ticket)();
    $this->ts->accept($this->pm, $t->id, $this->p->id);
    $i = $this->is->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => 'A', 'content_html' => '<p>x</p>']);
    ($this->toDev)($i);
    $pkg = $this->is->createPackage($this->dev, $this->p->id, 'v1');
    $this->is->transition($this->dev, $i->id, $this->p->id, 'test_pending', ['package_id' => $pkg, 'assignee_test_id' => $this->tester->id]);
    $this->is->transition($this->tester, $i->id, $this->p->id, 'test_doing');
    $this->is->transition($this->tester, $i->id, $this->p->id, 'release_pending', ['release_planned_at' => '2026-12-05']);
    $this->is->transition($this->tester, $i->id, $this->p->id, 'released');
    expect($t->fresh()->status)->toBe('pending_close');
    $this->ts->close($this->client, $t->id, $this->p->id);

    $titles = DB::table('user_notifications')->where('user_id', $this->pm->id)->pluck('title');
    expect($titles)->toContain("客戶已結案 {$t->ticket_no}");

    // default list hides it, the closed scope shows it
    ($this->s)($this->pm)->get(route('pm.index', ['oa' => 0]))->assertOk()->assertDontSee("pm/tickets/{$t->id}\" class=\"rounded-md bg-indigo-600", false);
    ($this->s)($this->pm)->get(route('pm.index', ['tt' => 'closed']))->assertOk()->assertSee("pm/tickets/{$t->id}\" class=\"rounded-md bg-indigo-600", false)->assertSee('已結案');
    ($this->s)($this->pm)->get(route('pm.index', ['tt' => 'all']))->assertOk()->assertSee($t->ticket_no);
    ($this->s)($this->pm)->get(route('pm.index', ['tt' => 'bogus']))->assertOk();
    ($this->s)($this->pm)->get(route('pm.export', ['tt' => 'closed']))->assertOk();
});

it('a package takes several fixes at once and sends them to test with the chosen tester', function () {
    $mk = fn ($title) => $this->is->createInternal($this->pm, $this->p->id, 'pm', ['title' => $title, 'content_html' => '<p>x</p>']);
    [$a, $b, $c, $d] = [$mk('A'), $mk('B'), $mk('C'), $mk('D')];
    foreach ([$a, $b, $d] as $x) {
        ($this->toDev)($x);
    }
    $pkg = $this->is->createPackage($this->dev, $this->p->id, 'v2');

    ($this->s)($this->dev)->get(route('dev.index'))->assertOk()->assertSee('套入並送測')->assertSee('指定測試人員')->assertSee($a->issue_no)->assertSee('pptest');
    // no tester and none on the issues -> refused, nothing changed (all or nothing)
    ($this->s)($this->dev)->post(route('dev.packages.issues.add', $pkg), ['issue_ids' => [$a->id, $b->id]])->assertSessionHas('err');
    expect($a->fresh()->status)->toBe('dev_doing')->and($a->fresh()->package_id)->toBeNull();
    // a non-tester cannot be assigned
    ($this->s)($this->dev)->post(route('dev.packages.issues.add', $pkg), ['issue_ids' => [$a->id], 'assignee_test_id' => $this->dev->id])->assertSessionHas('err');

    ($this->s)($this->dev)->post(route('dev.packages.issues.add', $pkg), ['issue_ids' => [$a->id, $b->id], 'assignee_test_id' => $this->tester->id])
        ->assertSessionHas('ok', '已套入 2 張問題單並送測。');
    foreach ([$a, $b] as $x) {
        $x = $x->fresh();
        expect($x->status)->toBe('test_pending')->and($x->package_id)->toBe($pkg)->and($x->assignee_test_id)->toBe($this->tester->id)->and($x->actual_done_at)->not->toBeNull();
    }
    expect(DB::table('user_notifications')->where('user_id', $this->tester->id)->count())->toBeGreaterThan(0);

    // C is still in PM stage; A is already waiting for test
    ($this->s)($this->dev)->post(route('dev.packages.issues.add', $pkg), ['issue_ids' => [$c->id], 'assignee_test_id' => $this->tester->id])->assertSessionHas('err');
    ($this->s)($this->dev)->post(route('dev.packages.issues.add', $pkg), ['issue_ids' => [$a->id], 'assignee_test_id' => $this->tester->id])->assertSessionHas('err');
    // tester (no dev.manage) cannot
    ($this->s)($this->tester)->post(route('dev.packages.issues.add', $pkg), ['issue_ids' => [$d->id]])->assertForbidden();

    // an issue that already has a tester (rework round) keeps it when none is chosen
    DB::table('issues')->where('id', $d->id)->update(['assignee_test_id' => $this->tester->id]);
    $this->is->addToPackage($this->dev, $this->p->id, $pkg, [$d->id]);
    expect($d->fresh()->status)->toBe('test_pending');

    // withdraw from test: back to DEV, tester kept and notified, no rework count, no button on the issue page
    ($this->s)($this->dev)->delete(route('dev.packages.issues.remove', [$pkg, $b->id]))->assertSessionHas('ok');
    $bb = $b->fresh();
    expect($bb->status)->toBe('dev_doing')->and($bb->stage)->toBe('DEV')->and($bb->package_id)->toBeNull()
        ->and($bb->actual_done_at)->toBeNull()->and($bb->rework_count)->toBe(0)->and($bb->assignee_test_id)->toBe($this->tester->id);
    expect(DB::table('user_notifications')->where('user_id', $this->tester->id)->pluck('title'))->toContain("{$b->issue_no} 已撤回送測（移出更新包 v2）");
    ($this->s)($this->dev)->get(route('issues.show', $b->id))->assertSee('撤回送測')->assertSee('套入更新包')->assertSee('v2');
    ($this->s)($this->tester)->get(route('issues.show', $a->id))->assertOk();
    expect(app(WorkflowService::class)->allowedTargets('issue', 'test_pending', $this->dev, $this->p->id)->pluck('code'))->not->toContain('dev_doing');
    // re-send keeps the tester
    $this->is->addToPackage($this->dev, $this->p->id, $pkg, [$b->id]);
    expect($b->fresh()->status)->toBe('test_pending');

    // testing started -> cannot be removed
    $this->is->transition($this->tester, $a->id, $this->p->id, 'test_doing');
    expect(fn () => $this->is->removeFromPackage($this->dev, $this->p->id, $pkg, $a->id))->toThrow(BusinessException::class, '仍有異常');
    // a DEV-stage issue in the package is just taken out
    $e = $mk('E');
    ($this->toDev)($e);
    DB::table('issues')->where('id', $e->id)->update(['package_id' => $pkg]);
    $this->is->removeFromPackage($this->dev, $this->p->id, $pkg, $e->id);
    expect($e->fresh()->package_id)->toBeNull()->and($e->fresh()->status)->toBe('dev_doing');

    // a released package is frozen
    DB::table('packages')->where('id', $pkg)->update(['released_at' => now()]);
    expect(fn () => $this->is->addToPackage($this->dev, $this->p->id, $pkg, [$e->id], $this->tester->id))->toThrow(BusinessException::class, '已上線');
});

it('freezes membership on the first release and keeps individual release confirmations available', function () {
    $makeReady = function (string $title) {
        $i = $this->is->createInternal($this->pm, $this->p->id, 'pm', ['title' => $title, 'content_html' => '<p>x</p>']);
        ($this->toDev)($i);

        return $i;
    };
    [$first, $second, $outsider, $waiting] = [$makeReady('A'), $makeReady('B'), $makeReady('C'), $makeReady('D')];
    $pkg = $this->is->createPackage($this->dev, $this->p->id, '凍結版本');
    $next = $this->is->createPackage($this->dev, $this->p->id, '下一版');
    $this->is->addToPackage($this->dev, $this->p->id, $pkg, [$first->id, $second->id, $waiting->id], $this->tester->id);
    foreach ([$first, $second] as $i) {
        $this->is->transition($this->tester, $i->id, $this->p->id, 'test_doing');
        $this->is->transition($this->tester, $i->id, $this->p->id, 'release_pending', ['release_planned_at' => '2026-12-05']);
    }
    expect(Package::findOrFail($pkg)->released_at)->toBeNull();
    $this->is->transition($this->tester, $first->id, $this->p->id, 'released');
    $releasedAt = Package::findOrFail($pkg)->released_at;
    expect($releasedAt)->not->toBeNull()->and($releasedAt->equalTo($first->fresh()->released_at))->toBeTrue();

    $handoff = ['to' => 'test_pending', 'package_id' => $pkg, 'assignee_test_id' => $this->tester->id];
    ($this->s)($this->dev)->post(route('issues.transition', $outsider->id), $handoff)
        ->assertSessionHas('err', '更新包 凍結版本 已上線，不能再變更內容。');
    ($this->s)($this->dev)->post(route('dev.packages.issues.add', $pkg), ['issue_ids' => [$outsider->id], 'assignee_test_id' => $this->tester->id])
        ->assertSessionHas('err');
    ($this->s)($this->dev)->delete(route('dev.packages.issues.remove', [$pkg, $waiting->id]))
        ->assertSessionHas('err', '更新包 凍結版本 已上線，不能再變更內容。');
    expect($outsider->fresh()->package_id)->toBeNull()
        ->and($waiting->fresh()->package_id)->toBe($pkg)->and($waiting->fresh()->status)->toBe('test_pending');
    ($this->s)($this->dev)->get(route('issues.show', $outsider->id))->assertOk()
        ->assertDontSee('凍結版本')->assertSee('下一版');

    $this->is->transition($this->tester, $waiting->id, $this->p->id, 'test_doing');
    $this->is->transition($this->tester, $waiting->id, $this->p->id, 'test_failed');
    $this->is->transition($this->dev, $waiting->id, $this->p->id, 'dev_doing');
    ($this->s)($this->dev)->get(route('issues.show', $waiting->id))->assertOk()
        ->assertSee('凍結版本')->assertDontSee('下一版')->assertDontSee('new_package_name');
    ($this->s)($this->dev)->post(route('issues.transition', $waiting->id), array_replace($handoff, ['package_id' => $next]))
        ->assertSessionHas('err', '更新包 凍結版本 已上線，不能再變更內容。');
    expect(fn () => $this->is->addToPackage($this->dev, $this->p->id, $next, [$outsider->id, $waiting->id], $this->tester->id))
        ->toThrow(BusinessException::class, '更新包 凍結版本 已上線');
    expect($outsider->fresh()->status)->toBe('dev_doing')->and($outsider->fresh()->package_id)->toBeNull();
    ($this->s)($this->dev)->post(route('issues.transition', $waiting->id), array_replace($handoff, ['package_id' => null, 'new_package_name' => '不應留空包']))
        ->assertSessionHas('err', '更新包 凍結版本 已上線，不能再變更內容。');
    expect(Package::where('project_id', $this->p->id)->where('name', '不應留空包')->exists())->toBeFalse();
    ($this->s)($this->dev)->post(route('issues.transition', $waiting->id), $handoff)->assertSessionHas('ok');
    expect($waiting->fresh()->status)->toBe('test_pending')->and($waiting->fresh()->package_id)->toBe($pkg);

    expect(fn () => $this->is->transition($this->tester, $second->id, $this->p->id, 'released', ['package_id' => null]))
        ->toThrow(BusinessException::class, '已上線');
    $this->travel(2)->minutes();
    ($this->s)($this->tester)->post(route('issues.transition', $second->id), ['to' => 'released', 'package_id' => null])->assertSessionHas('ok');
    expect(Package::findOrFail($pkg)->released_at->equalTo($releasedAt))->toBeTrue()
        ->and($second->fresh()->released_at->greaterThan($releasedAt))->toBeTrue();

    ($this->s)($this->dev)->post(route('issues.transition', $outsider->id), array_replace($handoff, ['package_id' => null, 'new_package_name' => '新建送測']))
        ->assertSessionHas('ok');
    expect($outsider->fresh()->status)->toBe('test_pending')->and($outsider->fresh()->package->name)->toBe('新建送測');
});

it('detects historical release evidence when the package timestamp is missing', function (string $status, bool $withTimestamp) {
    $makeReady = function (string $title) {
        $i = $this->is->createInternal($this->pm, $this->p->id, 'pm', ['title' => $title, 'content_html' => '<p>x</p>']);
        ($this->toDev)($i);

        return $i;
    };
    [$historical, $waiting, $outsider] = [$makeReady('A'), $makeReady('B'), $makeReady('C')];
    $pkg = $this->is->createPackage($this->dev, $this->p->id, '歷史版本');
    $this->is->addToPackage($this->dev, $this->p->id, $pkg, [$historical->id, $waiting->id], $this->tester->id);
    DB::table('issues')->where('id', $historical->id)->update(['status' => $status, 'released_at' => $withTimestamp ? now() : null]);
    expect(Package::findOrFail($pkg)->released_at)->toBeNull();
    expect(fn () => $this->is->addToPackage($this->dev, $this->p->id, $pkg, [$outsider->id], $this->tester->id))
        ->toThrow(BusinessException::class, '更新包 歷史版本 已上線');
    expect(fn () => $this->is->removeFromPackage($this->dev, $this->p->id, $pkg, $waiting->id))
        ->toThrow(BusinessException::class, '更新包 歷史版本 已上線');
    ($this->s)($this->dev)->get(route('dev.index'))->assertOk()->assertSee('歷史版本')->assertSee('已上線')
        ->assertDontSee(route('dev.packages.issues.remove', [$pkg, $waiting->id]));
    ($this->s)($this->dev)->get(route('issues.show', $outsider->id))->assertOk()->assertDontSee('歷史版本');
})->with([
    'released status alone' => ['released', false],
    'issue release timestamp alone' => ['release_pending', true],
]);
