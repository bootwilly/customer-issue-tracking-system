<?php

use App\Exceptions\BusinessException;
use App\Models\Project;
use App\Models\User;
use App\Services\Dev\DevService;
use App\Services\IssueService;
use App\Services\Test\TestService;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// Regression tests for the known issues listed in SESSION_LOG ("Known issues found while documenting").

function bfUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@bf.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'BF', 'name' => 'BF', 'ticket_prefix' => 'BF']);
    $this->client = bfUser('bfc', 'client', ['client_pm'], $this->p);
    $this->pm = bfUser('bfpm', 'internal', ['internal_pm'], $this->p);
    $this->dev = bfUser('bfdev', 'internal', ['dev'], $this->p);
    $this->tester = bfUser('bftest', 'internal', ['test'], $this->p);
    $this->is = app(IssueService::class);
    $this->ts = app(TicketService::class);
    $this->s = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
});

it('lands a project-admin-only user on a page they can open (was /pm 403)', function () {
    $pa = bfUser('bfpa', 'internal', ['project_admin'], $this->p);
    ($this->s)($pa)->get('/')->assertRedirect(route('meetings.index'));
    ($this->s)($pa)->get(route('meetings.index'))->assertOk();
    ($this->s)($this->pm)->get('/')->assertRedirect(route('pm.index'));
    ($this->s)($this->client)->get('/')->assertRedirect(route('tickets.index'));
});

it('makes DEV name a tester when handing off, and keeps a tester the issue already has', function () {
    $i = app(DevService::class)->createIssue($this->dev, $this->p->id, ['title' => 'dev found', 'content_html' => '<p>x</p>'], null);
    expect($i->assignee_dev_id)->toBe($this->dev->id); // shows under DEV "mine"
    $pkg = $this->is->createPackage($this->dev, $this->p->id, 'v1');
    $this->is->transition($this->dev, $i->id, $this->p->id, 'dev_doing', ['planned_due' => '2026-12-01']);
    $req = $this->is->abilities($i->fresh(), $this->dev)['targets']->firstWhere('code', 'test_pending')['requires'];
    expect($req)->toContain('assignee_test_id');
    expect(fn () => $this->is->transition($this->dev, $i->id, $this->p->id, 'test_pending', ['package_id' => $pkg]))
        ->toThrow(BusinessException::class, 'assignee_test_id');
    expect(fn () => $this->is->transition($this->dev, $i->id, $this->p->id, 'test_pending', ['package_id' => $pkg, 'assignee_test_id' => $this->pm->id]))
        ->toThrow(BusinessException::class, '權限');
    $this->is->transition($this->dev, $i->id, $this->p->id, 'test_pending', ['package_id' => $pkg, 'assignee_test_id' => $this->tester->id]);
    expect($i->fresh()->assignee_test_id)->toBe($this->tester->id);
    expect(DB::table('user_notifications')->where('user_id', $this->tester->id)->count())->toBeGreaterThan(0);

    // second round (test failed -> dev -> test): the tester is not asked again
    $this->is->transition($this->tester, $i->id, $this->p->id, 'test_doing');
    $this->is->transition($this->tester, $i->id, $this->p->id, 'test_failed');
    $this->is->transition($this->dev, $i->id, $this->p->id, 'dev_doing');
    $req = $this->is->abilities($i->fresh(), $this->dev)['targets']->firstWhere('code', 'test_pending')['requires'];
    expect($req)->not->toContain('assignee_test_id');
    $this->is->transition($this->dev, $i->id, $this->p->id, 'test_pending', ['package_id' => $pkg]);
    expect($i->fresh()->assignee_test_id)->toBe($this->tester->id);
});

it('makes the finding tester the re-tester of a test-created issue', function () {
    $i = app(TestService::class)->createIssue($this->tester, $this->p->id, ['title' => 'found', 'content_html' => '<p>x</p>', 'assignee_dev_id' => $this->dev->id], null);
    expect($i->assignee_test_id)->toBe($this->tester->id)->and($i->assignee_dev_id)->toBe($this->dev->id);
});

it('sends internal recipients to the PM ticket page and redirects old client links', function () {
    $t = $this->ts->create($this->client, $this->p->id, ['ticket_type' => 'anomaly', 'title' => 'link', 'content_html' => '<p>x</p>']);
    $url = DB::table('user_notifications')->where('user_id', $this->pm->id)->value('url');
    expect($url)->toBe("/pm/tickets/{$t->id}");
    $this->ts->accept($this->pm, $t->id, $this->p->id);
    expect(DB::table('user_notifications')->where('user_id', $this->client->id)->value('url'))->toBe("/tickets/{$t->id}");
    ($this->s)($this->pm)->get("/tickets/{$t->id}")->assertRedirect(route('pm.tickets.show', $t->id));
    ($this->s)($this->client)->get("/tickets/{$t->id}")->assertOk();
});

it('shows origin and role names instead of codes', function () {
    app(DevService::class)->createIssue($this->dev, $this->p->id, ['title' => 'origin label', 'content_html' => '<p>x</p>'], null);
    ($this->s)($this->dev)->get('/dev?tab=all&f=1')->assertOk()->assertSee('origin label')->assertSee('DEV')->assertDontSee('>dev<', false);
    $pa = bfUser('bfpa2', 'internal', ['project_admin'], $this->p);
    ($this->s)($pa)->get(route('padmin.members'))->assertOk()->assertSeeText('內部 PM')->assertSeeText('客戶 PM')->assertDontSeeText('internal_pm');
});
