<?php

use App\Exceptions\BusinessException;
use App\Models\ActivityEvent;
use App\Models\LookupItem;
use App\Models\Project;
use App\Models\ProjectFlow;
use App\Models\ProjectFlowDraftSnapshot;
use App\Models\ProjectFlowRevision;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Flow\FlowRenderer;
use App\Services\Flow\FlowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

function flwUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function flwMd(string $title = '入庫流程', string $node = '結束'): string
{
    return "# {$title}\n\n說明文字 **重點**\n\n```mermaid\nflowchart LR\n  A[開始] --> B[{$node}]\n```\n\n| 欄 | 值 |\n|---|---|\n| a | b |\n";
}

function flwSvg(array $labels = ['收料', '入庫'], int $pages = 1, bool $compress = false, string $extra = ''): string
{
    $cells = '';
    foreach ($labels as $i => $l) {
        $cells .= '<mxCell id="c'.$i.'" value="'.htmlspecialchars($l, ENT_QUOTES | ENT_XML1).'" vertex="1" parent="1"><mxGeometry width="80" height="40" as="geometry"/></mxCell>';
    }
    $model = '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'.$cells.'</root></mxGraphModel>';
    $diagrams = implode('', array_map(fn ($n) => '<diagram id="d'.$n.'" name="P'.$n.'">'.($compress ? base64_encode(gzdeflate(rawurlencode($model))) : $model).'</diagram>', range(1, $pages)));
    $file = '<mxfile host="test">'.$diagrams.'</mxfile>';

    return '<svg xmlns="http://www.w3.org/2000/svg" style="background: transparent; color-scheme: light dark;" width="100px" height="50px" viewBox="0 0 100 50" content="'
        .htmlspecialchars($file, ENT_QUOTES | ENT_XML1).'"><rect width="80" height="40" fill="light-dark(#fff, #000)"/>'.$extra.'</svg>';
}

beforeEach(function () {
    $this->withoutVite();
    $this->p = Project::create(['code' => 'FL', 'name' => 'FL', 'ticket_prefix' => 'FL']);
    $this->other = Project::create(['code' => 'FX', 'name' => 'FX', 'ticket_prefix' => 'FX']);
    $this->ipm = flwUser('flipm', 'internal', ['internal_pm'], $this->p);
    $this->cpm = flwUser('flcpm', 'client', ['client_pm'], $this->p);
    $this->cview = flwUser('flcview', 'client', ['client_view'], $this->p);
    $this->dev = flwUser('fldev', 'internal', ['dev'], $this->p);
    $this->test = flwUser('fltest', 'internal', ['test'], $this->p);
    $this->padm = flwUser('flpadm', 'internal', ['project_admin'], $this->p);
    $this->as = fn (User $u, ?Project $p = null) => $this->actingAs($u)->withSession(['project_id' => ($p ?? $this->p)->id]);
});

/** MD flow created as a draft (version 0). */
function flwDraft($t, User $u, string $md, string $title = '入庫流程'): ProjectFlow
{
    ($t->as)($u)->post(route('flows.store'), ['title' => $title, 'source_md' => $md])->assertRedirect();

    return ProjectFlow::latest('id')->first();
}

function flwPublish($t, User $u, ProjectFlow $f, string $note = '初版'): TestResponse
{
    return ($t->as)($u)->post(route('flows.publish', $f->id), ['note' => $note]);
}

/** Draft + publish -> effective v1. */
function flwCreate($t, User $u, string $md): ProjectFlow
{
    $f = flwDraft($t, $u, $md);
    flwPublish($t, $u, $f)->assertRedirect();

    return $f->fresh();
}

function flwSave($t, User $u, ProjectFlow $f, string $source, ?int $baseRev = null, string $title = '入庫流程'): TestResponse
{
    return ($t->as)($u)->putJson(route('flows.update', $f->id), array_filter(['title' => $title, 'source_md' => $source, 'base_rev' => $baseRev], fn ($v) => $v !== null));
}

function flwDrawio($t, User $u, string $svg, string $title = '入庫作業'): TestResponse
{
    return ($t->as)($u)->postJson(route('flows.store'), ['format' => 'drawio', 'title' => $title, 'source_md' => $svg]);
}

/* ---------- permissions ---------- */

it('lets every project role view a published flow and only PMs manage it', function () {
    $f = flwCreate($this, $this->ipm, flwMd());

    foreach ([$this->ipm, $this->cpm, $this->cview, $this->dev, $this->test, $this->padm] as $u) {
        ($this->as)($u)->get(route('flows.index'))->assertOk()->assertSee('入庫流程');
        ($this->as)($u)->get(route('flows.show', $f->id))->assertOk()->assertSee('class="mermaid"', false);
        ($this->as)($u)->get(route('flows.download', $f->id))->assertOk();
    }
    foreach ([$this->cview, $this->dev, $this->test] as $u) {
        ($this->as)($u)->get(route('flows.index'))->assertDontSee('匯入流程圖');
        ($this->as)($u)->post(route('flows.preview'), ['md_text' => flwMd()])->assertForbidden();
        ($this->as)($u)->post(route('flows.store'), ['title' => 'x', 'source_md' => flwMd()])->assertForbidden();
        ($this->as)($u)->post(route('flows.publish', $f->id), ['note' => 'x'])->assertForbidden();
        ($this->as)($u)->post(route('flows.status', $f->id), ['to' => 'obsolete'])->assertForbidden();
        ($this->as)($u)->post(route('flows.editors', $f->id), ['user_ids' => [$u->id]])->assertForbidden();
        ($this->as)($u)->delete(route('flows.destroy', $f->id))->assertForbidden();
        flwSave($this, $u, $f, flwMd('x'))->assertStatus(422)->assertJson(['ok' => false]);
    }
    ($this->as)($this->cpm)->get(route('flows.index'))->assertSee('匯入流程圖');
    ($this->as)($this->cpm)->delete(route('flows.destroy', $f->id))->assertForbidden();
    expect($f->fresh()->version)->toBe(1)->and($f->fresh()->deleted_at)->toBeNull();
});

/* ---------- draft / publish ---------- */

it('creates a draft first and publishes it as v1 with a change note', function () {
    $file = UploadedFile::fake()->createWithContent('倉儲流程.md', "\xEF\xBB\xBF".str_replace("\n", "\r\n", flwMd('')));
    $res = ($this->as)($this->cpm)->post(route('flows.preview'), ['md_file' => $file])->assertOk()->assertSee('語法檢查中')->assertSee('儲存為草稿');
    expect(ProjectFlow::count())->toBe(0)
        ->and($res->viewData('parsed')['title'])->toBe('倉儲流程')
        ->and($res->viewData('parsed')['md'])->not->toContain("\r");

    ($this->as)($this->cpm)->post(route('flows.store'), ['title' => '倉儲流程', 'source_md' => $res->viewData('parsed')['md']])->assertRedirect();
    $f = ProjectFlow::first();
    expect($f->version)->toBe(0)->and($f->status)->toBe('draft')->and($f->hasDraft())->toBeTrue()
        ->and(ProjectFlowRevision::count())->toBe(0);

    // members without edit rights cannot see an unpublished draft at all
    ($this->as)($this->cview)->get(route('flows.index', ['status' => '']))->assertDontSee('倉儲流程');
    ($this->as)($this->cview)->get(route('flows.show', $f->id))->assertNotFound();
    ($this->as)($this->cpm)->get(route('flows.show', $f->id))->assertOk()->assertSee('發布第 1 版');

    flwPublish($this, $this->cpm, $f, '')->assertSessionHasErrors('note');
    flwPublish($this, $this->cpm, $f, '第一次上線')->assertRedirect(route('flows.show', $f->id));
    $f->refresh();
    expect($f->version)->toBe(1)->and($f->status)->toBe('effective')->and($f->hasDraft())->toBeFalse()
        ->and(ProjectFlowRevision::where('flow_id', $f->id)->value('note'))->toBe('第一次上線')
        ->and(ActivityEvent::where(['subject_type' => 'flow', 'event_type' => 'flow_published', 'to_status' => 'effective'])->count())->toBe(1);
    ($this->as)($this->cview)->get(route('flows.show', $f->id))->assertOk()->assertSee('第一次上線');
    flwPublish($this, $this->cpm, $f, 'again')->assertSessionHas('err', '沒有草稿可以發布。');
});

it('rejects bad input with clear messages', function () {
    $svc = app(FlowService::class);
    expect(fn () => $svc->parse("# t\n\nno diagram", null, null))->toThrow(BusinessException::class, 'mermaid')
        ->and(fn () => $svc->parse("\xFF\xFE bad", null, null))->toThrow(BusinessException::class, 'UTF-8')
        ->and(fn () => $svc->parse("```mermaid\nflowchart LR\nA-->B\n```", null, null))->toThrow(BusinessException::class, '標題')
        ->and($svc->parse("~~~mermaid\nflowchart LR\nA-->B\n~~~", null, 'x.md')['title'])->toBe('x');

    ($this->as)($this->ipm)->post(route('flows.preview'), ['md_file' => UploadedFile::fake()->createWithContent('a.exe', flwMd())])->assertSessionHas('err');
    ($this->as)($this->ipm)->post(route('flows.preview'), ['md_text' => str_repeat('a', 200001)."\n```mermaid\nA\n```"])->assertSessionHas('err');
});

it('saves drafts without bumping the version, so 100 saves are still one version', function () {
    SystemSetting::where('key', 'flow.draft_keep')->update(['value' => '3']);
    $f = flwCreate($this, $this->ipm, flwMd());

    foreach (range(1, 5) as $i) {
        flwSave($this, $this->ipm, $f, flwMd('入庫流程', "步驟{$i}"))->assertOk()->assertJson(['ok' => true]);
    }
    $f->refresh();
    expect($f->version)->toBe(1)->and($f->draft_rev)->toBe(6)
        ->and(ProjectFlowRevision::where('flow_id', $f->id)->count())->toBe(1)
        ->and(ProjectFlowDraftSnapshot::where('flow_id', $f->id)->count())->toBe(3)
        ->and($f->source_md)->toBe(flwMd());

    // same content again: nothing written
    flwSave($this, $this->ipm, $f, flwMd('入庫流程', '步驟5'))->assertOk();
    expect($f->fresh()->draft_rev)->toBe(6)->and(session('ok'))->toBe('內容沒有變更。');

    // published v1 stays what members see; the draft is only for editors
    ($this->as)($this->dev)->get(route('flows.show', $f->id))->assertOk()->assertSee('B[結束]')->assertDontSee('步驟5');
    ($this->as)($this->dev)->get(route('flows.show', ['id' => $f->id, 'v' => 'draft']))->assertNotFound();
    ($this->as)($this->ipm)->get(route('flows.show', $f->id))->assertSee('有未發布的草稿');
    ($this->as)($this->ipm)->get(route('flows.show', ['id' => $f->id, 'v' => 'draft']))->assertOk()->assertSee('B[步驟5]')->assertSee('草稿儲存紀錄');
    ($this->as)($this->dev)->get(route('flows.index'))->assertDontSee('草稿修改中');
    ($this->as)($this->ipm)->get(route('flows.index'))->assertSee('草稿修改中');

    flwPublish($this, $this->ipm, $f, '加入步驟')->assertRedirect();
    expect($f->fresh()->version)->toBe(2)->and(ProjectFlowDraftSnapshot::where('flow_id', $f->id)->count())->toBe(0);
    ($this->as)($this->dev)->get(route('flows.show', ['id' => $f->id, 'v' => 1]))->assertOk()->assertSee('舊版本')->assertSee('B[結束]');
    $dl = ($this->as)($this->dev)->get(route('flows.download', ['id' => $f->id, 'v' => 1]))->assertOk();
    expect($dl->streamedContent())->toBe(flwMd())->and($dl->headers->get('content-type'))->toContain('text/markdown');
});

it('protects drafts with an optimistic lock, restores snapshots and discards drafts', function () {
    $f = flwCreate($this, $this->ipm, flwMd());
    flwSave($this, $this->ipm, $f, flwMd('入庫流程', 'A'), 1)->assertOk();          // rev 2
    flwSave($this, $this->cpm, $f, flwMd('入庫流程', 'B'), 2)->assertOk();          // rev 3
    $res = flwSave($this, $this->ipm, $f, flwMd('入庫流程', 'C'), 2)->assertStatus(422);  // stale editor
    expect($res->json('error'))->toContain('草稿已被 flcpm 更新')->and($f->fresh()->draft_source)->toContain('B[B]');

    $first = ProjectFlowDraftSnapshot::where('flow_id', $f->id)->orderBy('id')->first();
    ($this->as)($this->ipm)->post(route('flows.snapshots.restore', [$f->id, $first->id]))->assertRedirect();
    expect($f->fresh()->draft_source)->toContain('B[A]')->and($f->fresh()->draft_rev)->toBe(4);

    ($this->as)($this->ipm)->post(route('flows.discard', $f->id))->assertRedirect();
    expect($f->fresh()->hasDraft())->toBeFalse()->and($f->fresh()->version)->toBe(1)
        ->and(ProjectFlowDraftSnapshot::where('flow_id', $f->id)->count())->toBe(0);

    $never = flwDraft($this, $this->ipm, flwMd('新的'), '新的');
    ($this->as)($this->ipm)->post(route('flows.discard', $never->id))->assertSessionHas('err');
    expect($never->fresh()->hasDraft())->toBeTrue();
});

/* ---------- status ---------- */

it('moves flows between effective and obsolete and blocks editing obsolete ones', function () {
    $f = flwCreate($this, $this->ipm, flwMd());
    ($this->as)($this->ipm)->get(route('flows.show', $f->id))->assertSee('設為失效');

    ($this->as)($this->ipm)->post(route('flows.status', $f->id), ['to' => 'obsolete'])->assertRedirect();
    expect($f->fresh()->status)->toBe('obsolete');
    expect(flwSave($this, $this->ipm, $f, flwMd('入庫流程', 'X'))->assertStatus(422)->json('error'))->toContain('重新生效');

    // members: hidden from the default (effective) list, visible under the obsolete filter with a warning
    ($this->as)($this->dev)->get(route('flows.index'))->assertDontSee('入庫流程');
    ($this->as)($this->dev)->get(route('flows.index', ['status' => 'obsolete']))->assertSee('入庫流程');
    ($this->as)($this->dev)->get(route('flows.show', $f->id))->assertOk()->assertSee('僅供查閱');

    ($this->as)($this->ipm)->post(route('flows.status', $f->id), ['to' => 'effective'])->assertRedirect();
    expect($f->fresh()->status)->toBe('effective');
    ($this->as)($this->ipm)->post(route('flows.status', $f->id), ['to' => 'draft'])->assertSessionHas('err');

    $d = flwDraft($this, $this->ipm, flwMd('草'), '草');
    ($this->as)($this->ipm)->post(route('flows.status', $d->id), ['to' => 'effective'])->assertSessionHas('err', '草稿請用「發布」生效。');
    expect(ActivityEvent::where('event_type', 'flow_status')->count())->toBe(2);
});

/* ---------- editors ---------- */

it('lets assigned editors edit drafts but not publish', function () {
    $f = flwCreate($this, $this->ipm, flwMd());
    $d = flwDraft($this, $this->ipm, flwMd('草稿圖'), '草稿圖');

    // only project members are kept
    $outsider = flwUser('flout2', 'internal', ['dev'], $this->other);
    ($this->as)($this->ipm)->post(route('flows.editors', $d->id), ['user_ids' => [$this->dev->id, $outsider->id]])->assertRedirect();
    expect(app(FlowService::class)->editors($d->id)->pluck('user_id')->sort()->values()->all())->toBe([$this->dev->id]);

    ($this->as)($this->dev)->get(route('flows.index', ['status' => 'draft']))->assertSee('草稿圖');
    ($this->as)($this->test)->get(route('flows.index', ['status' => 'draft']))->assertDontSee('草稿圖');
    ($this->as)($this->dev)->get(route('flows.show', $d->id))->assertOk()->assertDontSee('發布第 1 版')->assertSee('上傳新內容');
    flwSave($this, $this->dev, $d, flwMd('草稿圖', 'DEV 改'), null, '草稿圖')->assertOk();
    expect($d->fresh()->draft_source)->toContain('DEV 改');
    flwPublish($this, $this->dev, $d, 'x')->assertForbidden();
    flwSave($this, $this->dev, $f, flwMd('入庫流程', 'no'))->assertStatus(422); // not an editor of $f

    // leaving the project drops the right without touching the editor table
    $this->p->members()->detach($this->dev->id);
    expect(app(FlowService::class)->isEditor($this->dev, $d->fresh()))->toBeFalse();
});

/* ---------- delete / trash ---------- */

it('soft deletes into the trash and lets the project admin restore or purge', function () {
    $f = flwCreate($this, $this->ipm, flwMd());
    ($this->as)($this->padm)->delete(route('flows.destroy', $f->id))->assertRedirect(route('flows.index'));
    expect($f->fresh()->deleted_at)->not->toBeNull();
    ($this->as)($this->ipm)->get(route('flows.index', ['status' => '']))->assertDontSee('入庫流程');
    ($this->as)($this->ipm)->get(route('flows.show', $f->id))->assertNotFound();

    ($this->as)($this->ipm)->get(route('padmin.trash'))->assertForbidden();
    ($this->as)($this->padm)->get(route('padmin.trash'))->assertOk()->assertSee('入庫流程');
    ($this->as)($this->padm)->post(route('padmin.trash.restore', $f->id))->assertRedirect();
    expect($f->fresh()->deleted_at)->toBeNull();

    ($this->as)($this->padm)->delete(route('flows.destroy', $f->id));
    ($this->as)($this->padm)->delete(route('padmin.trash.purge', $f->id), ['confirm_title' => '錯的'])->assertSessionHas('err');
    expect(ProjectFlow::find($f->id))->not->toBeNull();
    ($this->as)($this->padm)->delete(route('padmin.trash.purge', $f->id), ['confirm_title' => '入庫流程'])->assertSessionHas('ok');
    expect(ProjectFlow::find($f->id))->toBeNull()
        ->and(ProjectFlowRevision::where('flow_id', $f->id)->count())->toBe(0)
        ->and(ActivityEvent::where(['event_type' => 'flow_purged', 'subject_id' => $f->id])->first()->payload)->toMatchArray(['title' => '入庫流程', 'versions' => 1]);
});

it('does not leak flows across projects', function () {
    $f = flwCreate($this, $this->ipm, flwMd());
    $outsider = flwUser('flout', 'internal', ['internal_pm', 'project_admin'], $this->other);
    ($this->as)($outsider, $this->other)->get(route('flows.show', $f->id))->assertNotFound();
    ($this->as)($outsider, $this->other)->get(route('flows.download', $f->id))->assertNotFound();
    ($this->as)($outsider, $this->other)->post(route('flows.status', $f->id), ['to' => 'obsolete'])->assertSessionHas('err');
    ($this->as)($outsider, $this->other)->post(route('flows.publish', $f->id), ['note' => 'x'])->assertSessionHas('err');
    ($this->as)($outsider, $this->other)->putJson(route('flows.update', $f->id), ['title' => 'x', 'source_md' => flwMd('x')])->assertStatus(422);
    ($this->as)($outsider, $this->other)->delete(route('flows.destroy', $f->id))->assertSessionHas('err');
    ($this->as)($outsider, $this->other)->post(route('padmin.trash.restore', $f->id))->assertSessionHas('err');
    expect($f->fresh()->status)->toBe('effective')->and($f->fresh()->deleted_at)->toBeNull()->and($f->fresh()->hasDraft())->toBeFalse();
});

/* ---------- rendering ---------- */

it('renders markdown safely and escapes mermaid source', function () {
    $md = "# T\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[x](javascript:alert(1)) [ok](https://a.test)\n\n"
        ."```mermaid\nflowchart LR\n  A[\"<img src=x onerror=alert(1)>\"] --> B\n```\n\n| a | b |\n|---|---|\n| 1 | 2 |\n";
    $html = app(FlowRenderer::class)->render($md);
    expect($html)->not->toContain('<script')->not->toContain('<img src=x')->not->toContain('<img src="x"')->not->toContain('javascript:')
        ->toContain('<h1>T</h1>')->toContain('<table>')->toContain('href="https://a.test"')
        ->toContain('<pre class="mermaid">flowchart LR')
        ->toContain('&lt;img src=x onerror=alert(1)&gt;');
    expect(substr_count($html, 'class="cits-flow"'))->toBe(1);
});

it('keeps multiple diagrams in order and ignores other code fences', function () {
    $md = "# T\n\n```mermaid\nflowchart LR\nA-->B\n```\n\n```php\necho 1;\n```\n\n```mermaid\nsequenceDiagram\nA->>B: hi\n```\n";
    $html = app(FlowRenderer::class)->render($md);
    expect(substr_count($html, 'class="cits-flow"'))->toBe(2)
        ->and(strpos($html, 'flowchart LR'))->toBeLessThan(strpos($html, 'sequenceDiagram'))
        ->and($html)->toContain('echo 1;');
});

it('shows the flowchart link right after the project switcher for every project member', function () {
    foreach ([$this->cview, $this->dev] as $u) {
        ($this->as)($u)->get(route('flows.index'))->assertSeeInOrder(['id="project-switch"', '>流程圖<', '<nav'], false);
    }
});

/* ---------- draw.io ---------- */

it('creates a drawio draft from the editor and serves it as a locked-down image', function () {
    flwDrawio($this, $this->cpm, flwSvg(['收料', 'IQC <b>檢驗</b>', '收料']))->assertOk()->assertJson(['ok' => true]);
    $f = ProjectFlow::latest('id')->first();
    expect($f->format)->toBe('drawio')->and($f->status)->toBe('draft')
        ->and($f->draft_search_text)->toBe("收料\nIQC 檢驗")
        ->and($f->draft_source)->toContain('color-scheme: light;')->not->toContain('color-scheme: light dark');

    ($this->as)($this->cview)->get(route('flows.image', ['id' => $f->id, 'v' => 'draft']))->assertNotFound();
    $di = ($this->as)($this->cpm)->get(route('flows.image', ['id' => $f->id, 'v' => 'draft']))->assertOk();
    expect($di->headers->get('cache-control'))->toContain('no-store');
    flwPublish($this, $this->cpm, $f)->assertRedirect();
    $f->refresh();
    expect($f->search_text)->toBe("收料\nIQC 檢驗")->and(ProjectFlowRevision::where('flow_id', $f->id)->value('format'))->toBe('drawio');

    ($this->as)($this->dev)->get(route('flows.show', $f->id))->assertOk()
        ->assertSee(route('flows.image', ['id' => $f->id, 'v' => 1]), false)->assertSee('線上繪製（draw.io）')
        ->assertDontSee('線上編輯')->assertDontSee('class="mermaid"', false);
    ($this->as)($this->cpm)->get(route('flows.show', $f->id))->assertSee('線上編輯')->assertDontSee('上傳新內容');

    $img = ($this->as)($this->cview)->get(route('flows.image', $f->id))->assertOk();
    expect($img->headers->get('content-type'))->toContain('image/svg+xml')
        ->and($img->headers->get('content-security-policy'))->toContain('sandbox')->toContain("default-src 'none'")
        ->and($img->headers->get('x-content-type-options'))->toBe('nosniff');

    $dl = ($this->as)($this->cview)->get(route('flows.download', $f->id))->assertOk();
    expect($dl->headers->get('content-disposition'))->toContain('_v1.drawio.svg')->and($dl->streamedContent())->toBe($f->source_md);

    foreach ([$this->cview, $this->dev, $this->test] as $u) {
        ($this->as)($u)->get(route('flows.create'))->assertForbidden();
        ($this->as)($u)->get(route('flows.edit', $f->id))->assertForbidden();
        flwDrawio($this, $u, flwSvg())->assertForbidden();
    }
    ($this->as)($this->ipm)->get(route('flows.create'))->assertOk()->assertSee('drawio/index.html?embed=1', false);
    ($this->as)($this->ipm)->get(route('flows.edit', $f->id))->assertOk()->assertSee('name="base_rev" value="1"', false)
        ->assertSee(route('flows.image', ['id' => $f->id, 'v' => 1]), false);
});

it('accepts compressed pages and data URIs from the editor', function () {
    flwDrawio($this, $this->ipm, 'data:image/svg+xml;base64,'.base64_encode(flwSvg(['壓縮節點'], 1, true)))->assertOk();
    expect(ProjectFlow::latest('id')->first()->draft_search_text)->toBe('壓縮節點');
});

it('rejects unsafe or unsupported drawio content', function (string $svg, string $msg) {
    $res = flwDrawio($this, $this->ipm, $svg)->assertStatus(422)->assertJson(['ok' => false]);
    expect($res->json('error'))->toContain($msg)->and(ProjectFlow::count())->toBe(0);
})->with([
    'script' => [fn () => flwSvg(extra: '<script>alert(1)</script>'), '程式碼'],
    'event attr' => [fn () => flwSvg(extra: '<rect onload="alert(1)"/>'), '事件屬性'],
    'js link' => [fn () => flwSvg(extra: '<a href="javascript:alert(1)"><text>x</text></a>'), 'javascript'],
    'doctype' => [fn () => '<!DOCTYPE svg [<!ENTITY x "y">]>'.flwSvg(), 'DOCTYPE'],
    'two pages' => [fn () => flwSvg(pages: 2), '只能有一頁'],
    'plain svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>', '原始資料'],
    'not svg' => ['<html><body>x</body></html>', '有效的 SVG'],
    'no diagram' => [fn () => str_replace(['&lt;diagram', '&lt;/diagram'], ['&lt;page', '&lt;/page'], flwSvg()), '沒有圖'],
]);

it('enforces the drawio size limit from settings', function () {
    SystemSetting::where('key', 'flow.drawio_max_bytes')->update(['value' => '500']);
    $res = flwDrawio($this, $this->ipm, flwSvg(array_map(fn ($i) => "節點{$i}", range(1, 30))))->assertStatus(422);
    expect($res->json('error'))->toContain('過大');
});

it('edits drawio drafts from the editor without new versions', function () {
    flwDrawio($this, $this->ipm, flwSvg())->assertOk();
    $f = ProjectFlow::latest('id')->first();
    flwPublish($this, $this->ipm, $f)->assertRedirect();

    flwSave($this, $this->cpm, $f->fresh(), flwSvg(['收料', '上架']), 1, '入庫作業')->assertOk()->assertJson(['redirect' => route('flows.show', $f->id)]);
    $f->refresh();
    expect($f->version)->toBe(1)->and($f->draft_rev)->toBe(2)->and($f->draft_search_text)->toBe("收料\n上架");
    ($this->as)($this->ipm)->get(route('flows.edit', $f->id))->assertOk()->assertSee('編輯草稿')
        ->assertSee(route('flows.image', ['id' => $f->id, 'v' => 'draft']), false);
    expect(flwSave($this, $this->ipm, $f, flwSvg(['收料', '退貨']), 1, '入庫作業')->assertStatus(422)->json('error'))->toContain('草稿已被');
});

it('keeps mermaid and drawio paths apart', function () {
    $md = flwCreate($this, $this->ipm, flwMd());
    flwDrawio($this, $this->ipm, flwSvg())->assertOk();
    $dw = ProjectFlow::latest('id')->first();

    ($this->as)($this->ipm)->get(route('flows.edit', $md->id))->assertRedirect(route('flows.show', $md->id))->assertSessionHas('err');
    ($this->as)($this->ipm)->get(route('flows.image', $md->id))->assertNotFound();
    ($this->as)($this->ipm)->post(route('flows.preview'), ['flow_id' => $dw->id, 'md_text' => flwMd()])->assertSessionHas('err');
    flwSave($this, $this->ipm, $dw, flwMd())->assertStatus(422);
    expect(($this->as)($this->ipm)->postJson(route('flows.store'), ['format' => 'visio', 'title' => 'x', 'source_md' => flwSvg()])->assertStatus(422)->json('error'))
        ->toBe('不支援的流程圖格式。')
        ->and($md->fresh()->format)->toBe('mermaid')->and($md->fresh()->search_text)->toContain('flowchart LR');
});

it('searches titles and diagram text, and hides disabled formats', function () {
    foreach ([['IQC 檢驗', '品檢'], ['出貨', '出貨流程']] as [$label, $title]) {
        flwDrawio($this, $this->ipm, flwSvg([$label]), $title)->assertOk();
        flwPublish($this, $this->ipm, ProjectFlow::latest('id')->first());
    }

    ($this->as)($this->dev)->get(route('flows.index', ['q' => 'IQC']))->assertSee('品檢')->assertDontSee('出貨流程');
    ($this->as)($this->dev)->get(route('flows.index', ['q' => '出貨']))->assertSee('出貨流程')->assertDontSee('品檢');
    ($this->as)($this->dev)->get(route('flows.index', ['q' => '%']))->assertSee('找不到');
    ($this->as)($this->ipm)->get(route('flows.index'))->assertSee('＋ 線上繪製（draw.io）')->assertSee('＋ 匯入 MD（Mermaid）');

    LookupItem::where(['category' => 'flow_format', 'code' => 'drawio'])->update(['is_active' => false]);
    ($this->as)($this->ipm)->get(route('flows.index'))->assertDontSee('線上繪製（draw.io）')->assertSee('＋ 匯入 MD（Mermaid）');
    ($this->as)($this->ipm)->get(route('flows.create'))->assertNotFound();
    flwDrawio($this, $this->ipm, flwSvg())->assertStatus(422);
});

it('accepts the online editor SVG as a file part, also past the 1 MB text-field limit of the host', function () {
    $svg = flwSvg(array_map(fn ($n) => "節點 $n ".str_repeat("說明", 100), range(1, 1500)));
    expect(strlen($svg))->toBeGreaterThan(1_100_000);
    $file = fn (string $s) => UploadedFile::fake()->createWithContent('flow.svg', $s);

    ($this->as)($this->ipm)->post(route('flows.store'), ['format' => 'drawio', 'title' => '大圖', 'source_file' => $file($svg)],
        ['Accept' => 'application/json'])->assertOk()->assertJson(['ok' => true]);
    $f = ProjectFlow::latest('id')->first();
    expect($f->format)->toBe('drawio')->and($f->draft_search_text)->toContain('節點 1500');

    $svg2 = flwSvg(['改過的節點']);
    ($this->as)($this->ipm)->post(route('flows.update', $f->id), ['_method' => 'PUT', 'title' => '大圖', 'source_file' => $file($svg2)],
        ['Accept' => 'application/json'])->assertOk();
    expect($f->fresh()->draft_search_text)->toBe('改過的節點');

    ($this->as)($this->ipm)->post(route('flows.store'), ['format' => 'drawio', 'title' => 'x'], ['Accept' => 'application/json'])
        ->assertStatus(422);
});
