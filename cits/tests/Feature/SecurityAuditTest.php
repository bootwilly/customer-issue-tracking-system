<?php

use App\Models\Attachment;
use App\Models\Issue;
use App\Models\IssueAddendum;
use App\Models\Meeting;
use App\Models\MeetingItem;
use App\Models\Project;
use App\Models\ProjectFlow;
use App\Models\SystemSetting;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\IssueService;
use App\Services\PasswordResetService;
use App\Services\SanitizerService;
use App\Services\TicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// fixes for docs/security-audit-20261127.md (H-01..H-06, M-02, M-08)

function saUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@sa.test", 'password' => 'secret1', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function saPng(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('a.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
}

beforeEach(function () {
    $this->withoutVite();
    $this->p = Project::create(['code' => 'SA', 'name' => 'SA', 'ticket_prefix' => 'SA']);
    $this->q = Project::create(['code' => 'SB', 'name' => 'SB', 'ticket_prefix' => 'SB']);
    $this->pm = saUser('sapm', 'internal', ['internal_pm'], $this->p);
    $this->cpm = saUser('sacpm', 'client', ['client_pm'], $this->p);
    $this->as = fn (User $u, ?Project $p = null) => $this->actingAs($u)->withSession(['project_id' => ($p ?? $this->p)->id]);
});

it('H-01 quotes only comments of a ticket linked to the issue in the same project', function () {
    $mk = fn (Project $p, User $c) => app(TicketService::class)->create($c, $p->id, ['ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => 't', 'content_html' => '<p>x</p>']);
    $t = $mk($this->p, $this->cpm);
    ($this->as)($this->pm)->post("/pm/tickets/{$t->id}/accept")->assertSessionHas('ok');
    ($this->as)($this->pm)->post("/pm/tickets/{$t->id}/issue", ['title' => 'i', 'severity' => 3, 'content_html' => '<p>i</p>']);
    $issue = Issue::latest('id')->first();
    $mine = TicketComment::create(['ticket_id' => $t->id, 'kind' => 'supplement', 'content_html' => '<p>mine</p>', 'created_by' => $this->cpm->id, 'created_at' => now()]);

    $otherClient = saUser('saother', 'client', ['client_pm'], $this->q);
    $foreign = TicketComment::create(['ticket_id' => $mk($this->q, $otherClient)->id, 'kind' => 'supplement', 'content_html' => '<p>secret-of-SB</p>', 'created_by' => $otherClient->id, 'created_at' => now()]);

    ($this->as)($this->pm)->post("/pm/tickets/{$t->id}/quote", ['issue_id' => $issue->id, 'comment_id' => $foreign->id])->assertSessionHas('err');
    expect(IssueAddendum::where('issue_id', $issue->id)->count())->toBe(0);

    ($this->as)($this->pm)->post("/pm/tickets/{$t->id}/quote", ['issue_id' => $issue->id, 'comment_id' => $mine->id])->assertSessionHas('ok');
    expect(IssueAddendum::where('issue_id', $issue->id)->value('content_html'))->toContain('mine');
});

it('H-02 escapes user text inside inline confirm handlers', function () {
    $padm = saUser('sapadm', 'internal', ['project_admin'], $this->p);
    ($this->as)($this->pm)->post(route('flows.store'), ['title' => "x');alert('sa-2", 'source_md' => '# T

```mermaid
flowchart LR
A-->B
```
'])->assertRedirect();
    $f = ProjectFlow::latest('id')->first();
    $html = ($this->as)($padm)->get(route('flows.show', $f->id))->assertOk()->getContent();
    preg_match_all('/onsubmit="([^"]*)"/', $html, $m);
    $handler = collect($m[1])->first(fn ($h) => str_contains($h, 'sa-2'));
    // inside the attribute the quote must be a JS escape, not an HTML entity the browser decodes back to '
    expect($handler)->not->toBeNull()->not->toContain('&#039;')->toContain('\u0027');
});

it('H-03 ignores a forged forwarded host and keeps the reset-link cooldown', function () {
    $this->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Prefix' => '/evil'])->get('/')
        ->assertOk()->assertDontSee('evil.example')->assertDontSee('/evil/');

    SystemSetting::query()->updateOrCreate(['key' => 'mail.enabled'], ['value' => '1']);
    $svc = app(PasswordResetService::class);
    $svc->request('sapm', '203.0.113.1');
    $first = DB::table('password_reset_tokens')->value('token');
    $svc->request('sapm', '203.0.113.2');
    expect($first)->not->toBeNull()->and(DB::table('password_reset_tokens')->value('token'))->toBe($first);
});

it('H-04 never returns internal issue numbers to clients in item JSON', function () {
    $issue = Issue::create(['project_id' => $this->p->id, 'issue_no' => 'SA-77', 'origin' => 'pm', 'title' => 'x', 'content_html' => '<p>x</p>',
        'stage' => 'PM', 'status' => 'new', 'created_by' => $this->pm->id]);
    ($this->as)($this->cpm)->post(route('meetings.store'), ['type' => 'regular', 'title' => 'w', 'start_at' => '2026-12-01 10:00', 'mode' => 'scheduled'])->assertRedirect();
    $m = Meeting::latest('id')->first();
    $id = ($this->as)($this->cpm)->postJson(route('meetings.items.store', $m->id), ['topic' => 't'])->assertOk()->json('item.id');
    MeetingItem::whereKey($id)->update(['issue_id' => $issue->id]);

    ($this->as)($this->cpm)->putJson(route('meetings.items.update', [$m->id, $id]), ['topic' => 't2'])
        ->assertOk()->assertJsonPath('item.issue_no', null)->assertJsonPath('item.issue_url', null);
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m->id, $id]), ['topic' => 't3'])
        ->assertOk()->assertJsonPath('item.issue_no', 'SA-77');
});

it('H-05 keeps only attachment images and refuses embedded edit-lock requests', function () {
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);
    $s = app(SanitizerService::class);
    foreach (['/attachments/5', "http://$host/attachments/5", "https://$host/mescits/attachments/5"] as $ok) {
        expect($s->clean('<img src="'.$ok.'">'))->toContain($ok);
    }
    foreach (["http://$host/tickets/1/edit", '/tickets/1/edit', "http://$host/attachments/5?x=1", "//$host/attachments/5", 'https://evil.example/attachments/5', "http://$host/flows/1/image"] as $bad) {
        expect($s->clean('<img src="'.$bad.'">'))->not->toContain('src=');
    }

    $t = app(TicketService::class)->create($this->cpm, $this->p->id, ['ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => 't', 'content_html' => '<p>x</p>']);
    ($this->as)($this->cpm)->withHeaders(['Sec-Fetch-Dest' => 'image'])->get("/tickets/{$t->id}/edit")->assertForbidden();
    ($this->as)($this->cpm)->withHeaders(['Sec-Fetch-Dest' => 'document'])->get("/tickets/{$t->id}/edit")->assertOk();
});

it('H-06 rejects active SVG content that slips past the text filters and caps inflation', function (string $extra) {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" content="'
        .htmlspecialchars('<mxfile><diagram id="d"><mxGraphModel><root><mxCell id="0"/></root></mxGraphModel></diagram></mxfile>', ENT_QUOTES | ENT_XML1).'"><rect/>'.$extra.'</svg>';
    ($this->as)($this->pm)->postJson(route('flows.store'), ['format' => 'drawio', 'title' => 'd', 'source_md' => $svg])->assertStatus(422);
    expect(ProjectFlow::count())->toBe(0);
})->with([
    'namespaced script' => ['<x:script xmlns:x="http://www.w3.org/2000/svg">alert(1)</x:script>'],
    '> in an earlier attribute' => ['<a title=">" onclick="alert(1)"><text>x</text></a>'],
    'entity-encoded scheme' => ['<a xlink:href="&#106;avascript:alert(1)"><text>x</text></a>'],
    'SMIL set' => ['<a><set attributeName="href" to="x"/><text>x</text></a>'],
    'data html' => ['<a href="data:text/html,x"><text>x</text></a>'],
]);

it('H-06 refuses a compressed page that inflates past the size limit', function () {
    SystemSetting::query()->updateOrCreate(['key' => 'flow.drawio_max_bytes'], ['value' => '100000']);
    $bomb = base64_encode(gzdeflate(str_repeat(' ', 2_000_000)));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" content="'
        .htmlspecialchars('<mxfile><diagram id="d">'.$bomb.'</diagram></mxfile>', ENT_QUOTES | ENT_XML1).'"><rect/></svg>';
    ($this->as)($this->pm)->postJson(route('flows.store'), ['format' => 'drawio', 'title' => 'd', 'source_md' => $svg])->assertStatus(422);
});

it('M-08 binds images pasted while editing an issue', function () {
    Storage::fake('local');
    $i = app(IssueService::class)->createInternal($this->pm, $this->p->id, 'pm', ['title' => 'e', 'content_html' => '<p>x</p>', 'severity' => 3]);
    $ctx = "issue:edit:{$i->id}";
    $aid = ($this->as)($this->pm)->postJson('/uploads/image', ['file' => saPng(), 'context' => $ctx])->assertOk()->json('attachment_id');
    ($this->as)($this->pm)->post(route('issues.fields', $i->id), ['content_html' => '<p>y</p><img src="/attachments/'.$aid.'">', 'draft' => $ctx])->assertSessionHas('ok');
    expect(Attachment::find($aid)->only(['state', 'owner_type', 'owner_id']))->toBe(['state' => 'bound', 'owner_type' => 'issue', 'owner_id' => $i->id]);
});

it('M-02 serves bound attachments by view permission, not by membership or authorship', function () {
    Storage::fake('local');
    $padm = saUser('sapadm2', 'internal', ['project_admin'], $this->p);
    $i = app(IssueService::class)->createInternal($this->pm, $this->p->id, 'pm', ['title' => 'e', 'content_html' => '<p>x</p>', 'severity' => 3]);
    $ctx = "issue:edit:{$i->id}";
    $aid = ($this->as)($this->pm)->postJson('/uploads/image', ['file' => saPng(), 'context' => $ctx])->assertOk()->json('attachment_id');
    ($this->as)($this->pm)->post(route('issues.fields', $i->id), ['content_html' => '<img src="/attachments/'.$aid.'">', 'draft' => $ctx]);

    ($this->as)($this->pm)->get("/attachments/$aid")->assertOk();
    ($this->as)($padm)->get("/attachments/$aid")->assertForbidden();   // member without issue.view

    $this->p->members()->detach($this->pm->id);                       // uploader left the project
    ($this->as)($this->pm->fresh())->get("/attachments/$aid")->assertForbidden();

    $this->p->members()->attach($this->pm->id);
    $this->pm->update(['status' => 'disabled']);                       // disabled account, session still alive
    $before = Attachment::count();
    $this->actingAs($this->pm->fresh())->postJson('/uploads/image', ['file' => saPng()])->assertRedirect();
    expect(Attachment::count())->toBe($before);
});
