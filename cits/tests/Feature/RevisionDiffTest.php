<?php

use App\Models\ClientTicket;
use App\Models\Project;
use App\Models\TicketRevision;
use App\Models\User;
use App\Services\RevisionDiffService;
use App\Services\TicketService;
use Spatie\Permission\PermissionRegistrar;

function mdrUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function mdrTicket(User $client, Project $p, string $html1, string $html2): ClientTicket
{
    $t = app(TicketService::class)->create($client, $p->id, ['ticket_type' => 'anomaly', 'title' => '第一版標題', 'content_html' => $html1]);
    TicketRevision::create(['ticket_id' => $t->id, 'rev_no' => 2, 'title' => '第二版標題', 'content_snapshot' => $html2, 'created_by' => $client->id, 'created_at' => now()]);
    $t->forceFill(['current_rev' => 2])->save();

    return $t->fresh();
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'RV1', 'name' => 'RV1', 'ticket_prefix' => 'RV1']);
    $this->p2 = Project::create(['code' => 'RV2', 'name' => 'RV2', 'ticket_prefix' => 'RV2']);
    $this->client = mdrUser('rvclient', 'client', ['client_pm'], $this->p);
    $this->client2 = mdrUser('rvclient2', 'client', ['client_pm'], $this->p2);
    $this->pm = mdrUser('rvpm', 'internal', ['internal_pm'], $this->p);
    $this->as = fn (User $u, Project $p) => $this->actingAs($u)->withSession(['project_id' => $p->id]);
});

it('converts html to lines and diffs them (incl. CJK)', function () {
    $s = app(RevisionDiffService::class);
    expect($s->toLines('<p>甲&amp;乙</p><p>第二行<br>第三行</p>'))->toBe(['甲&乙', '第二行', '第三行']);

    $d = $s->diffLines(['一', '二', '三'], ['一', '二改', '三', '四']);
    expect(array_column($d, 'type'))->toBe(['same', 'del', 'add', 'same', 'add'])
        ->and($d[1]['text'])->toBe('二')->and($d[2]['text'])->toBe('二改');

    expect(array_column($s->diffHtml(null, '<p>新</p>'), 'type'))->toBe(['add'])
        ->and(array_column($s->diffHtml('<p>舊</p>', ''), 'type'))->toBe(['del']);
});

it('shows diff to client in same project and to internal users', function () {
    $t = mdrTicket($this->client, $this->p, '<p>相同行</p><p>舊內容</p>', '<p>相同行</p><p>新內容</p>');
    $url = route('tickets.revisions.diff', [$t->id, 2]);

    ($this->as)($this->client, $this->p)->get($url)->assertOk()->assertSee('舊內容')->assertSee('新內容')->assertSee('第二版標題')
        ->assertSee(route('tickets.show', $t->id), false);
    ($this->as)($this->pm, $this->p)->get($url)->assertOk()->assertSee(route('pm.tickets.show', $t->id), false);
    ($this->as)($this->client, $this->p)->get(route('tickets.revisions.diff', [$t->id, 1]))->assertOk();
    ($this->as)($this->client, $this->p)->get(route('tickets.revisions.diff', [$t->id, 9]))->assertNotFound();
});

it('404s for tickets of another project', function () {
    $t = mdrTicket($this->client, $this->p, '<p>a</p>', '<p>b</p>');
    ($this->as)($this->client2, $this->p2)->get(route('tickets.revisions.diff', [$t->id, 2]))->assertNotFound();
});

it('escapes revision content in the diff view', function () {
    $t = mdrTicket($this->client, $this->p, '<p>ok</p>', '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
    $res = ($this->as)($this->client, $this->p)->get(route('tickets.revisions.diff', [$t->id, 2]))->assertOk();
    $res->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});
