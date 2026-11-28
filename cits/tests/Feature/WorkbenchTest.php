<?php

use App\Models\ActivityEvent;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\IssueCloseTask;
use App\Models\Meeting;
use App\Models\MeetingItem;
use App\Models\Project;
use App\Models\User;
use App\Services\Collab\SavedQueryService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

function wbMember(Project $project, string $account, array $roles, string $type = 'internal'): User
{
    $user = User::create(['account' => $account, 'name' => $account, 'email' => "$account@wb.test", 'password' => 'test123', 'user_type' => $type, 'status' => 'active']);
    $project->members()->attach($user->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($project->id);
    $user->assignRole($roles);

    return $user->fresh();
}

function wbIssue(Project $project, User $by, string $title, array $attrs = []): Issue
{
    return Issue::create($attrs + ['project_id' => $project->id, 'created_by' => $by->id, 'issue_no' => $title, 'title' => $title,
        'origin' => 'pm', 'stage' => 'DEV', 'status' => 'dev_todo', 'content_html' => 'x']);
}

function wbTicket(Project $project, User $by, string $title, string $status): ClientTicket
{
    return ClientTicket::create(['project_id' => $project->id, 'created_by' => $by->id, 'ticket_no' => $title, 'title' => $title,
        'ticket_type' => 'bug', 'status' => $status, 'content_html' => 'x']);
}

beforeEach(function () {
    $this->project = Project::create(['code' => 'WB', 'name' => 'Workbench', 'ticket_prefix' => 'WB']);
    $this->staff = wbMember($this->project, 'staff', ['dev', 'test']);
    $this->pm = wbMember($this->project, 'lead', ['internal_pm']);
    $this->client = wbMember($this->project, 'customer', ['client_pm'], 'client');
    $this->board = fn ($user, $query = []) => $this->actingAs($user)->withSession(['project_id' => $this->project->id])
        ->get(route('workbench', $query))->assertOk();
});

it('moves assigned work between development, testing and closing without counting stale assignees', function () {
    $tester = wbMember($this->project, 'tester', ['test']);
    $issue = wbIssue($this->project, $this->pm, 'handoff-item', ['assignee_dev_id' => $this->staff->id, 'assignee_test_id' => $tester->id, 'current_due' => '2026-12-01']);
    ($this->board)($this->staff)->assertSeeText('handoff-item')->assertSeeText('處理開發');
    ($this->board)($tester)->assertDontSeeText('handoff-item');
    $issue->update(['status' => 'test_scheduled', 'stage' => 'TEST', 'test_scheduled_at' => '2026-12-03']);
    ($this->board)($this->staff)->assertDontSeeText('handoff-item');
    ($this->board)($tester, ['tab' => 'schedule'])->assertSeeText('handoff-item')->assertSeeText('2026-12-03');
    $issue->update(['status' => 'test_failed', 'stage' => 'DEV']);
    ($this->board)($this->staff)->assertSeeText('仍有異常');
    ($this->board)($tester)->assertDontSeeText('handoff-item');

    $issue->update(['status' => 'closing', 'stage' => 'CLOSE', 'close_target' => 'closed_pm']);
    foreach (['dev', 'test', 'release'] as $role) {
        IssueCloseTask::create(['issue_id' => $issue->id, 'round' => 1, 'role' => $role, 'user_id' => $role === 'release' ? null : $this->staff->id, 'status' => 'pending']);
    }
    ($this->board)($this->staff)->assertSee('#close-tasks')->assertViewHas('counts', fn ($c) => $c['todo'] === 1 && $c['schedule'] === 0 && $c['team'] === 1);
    ($this->board)($tester, ['tab' => 'team'])->assertSeeText('完成結案收尾');
    ($this->board)($this->pm, ['tab' => 'team'])->assertDontSeeText('handoff-item');
    IssueCloseTask::where('issue_id', $issue->id)->whereNotNull('user_id')->update(['status' => 'done']);
    ($this->board)($this->staff)->assertDontSeeText('handoff-item');
    $issue->update(['deleted_at' => now()]);
    ($this->board)($tester, ['tab' => 'team'])->assertDontSeeText('handoff-item');
});

it('only lists owned open meeting items with visible content in live project meetings', function () {
    $other = Project::create(['code' => 'OTH', 'name' => 'Other', 'ticket_prefix' => 'OT']);
    $item = function ($label, array $meeting = [], array $attrs = []) {
        $m = Meeting::create($meeting + ['project_id' => $this->project->id, 'title' => $label, 'created_by' => $this->pm->id,
            'status' => 'recording', 'customer_visible' => true, 'type' => 'weekly', 'start_at' => now()]);

        return MeetingItem::create($attrs + ['meeting_id' => $m->id, 'topic' => $label, 'created_by' => $this->pm->id, 'owner_id' => $this->client->id,
            'status' => 'open', 'due_date' => '2026-11-30']);
    };
    $visible = $item('published-topic', ['published_at' => now()]);
    $item('client-created-topic', ['created_by' => $this->client->id]);
    $item('draft-topic');
    $item('internal-topic', ['customer_visible' => false, 'published_at' => now()]);
    $item('deleted-topic', ['deleted_at' => now(), 'published_at' => now()]);
    $item('other-project-topic', ['project_id' => $other->id, 'published_at' => now()]);
    $item('finished-topic', ['published_at' => now()], ['status' => 'done']);
    $item('cancelled-topic', ['published_at' => now(), 'status' => 'cancelled']);
    $item('another-owner', ['published_at' => now()], ['owner_id' => $this->pm->id]);
    foreach (['todo', 'schedule'] as $tab) {
        ($this->board)($this->client, ['tab' => $tab])->assertSeeText('published-topic')->assertSeeText('client-created-topic')->assertSee('#item-'.$visible->id)
            ->assertDontSeeText('internal-topic')->assertDontSeeText('draft-topic')->assertDontSeeText('deleted-topic')->assertDontSeeText('other-project-topic')
            ->assertDontSeeText('finished-topic')->assertDontSeeText('cancelled-topic')->assertDontSeeText('another-owner')
            ->assertViewHas('counts', fn ($c) => $c['todo'] === 2 && $c['schedule'] === 2);
    }
    $this->actingAs($this->client)->withSession(['project_id' => $other->id])->get(route('workbench'))->assertRedirect(route('projects.select'));
});

it('separates team intake and due approvals from assigned PM and client actions', function () {
    $ticket = wbTicket($this->project, $this->client, 'customer-intake', 'submitted');
    $issue = wbIssue($this->project, $this->pm, 'due-approval');
    $request = DB::table('due_change_requests')->insertGetId(['issue_id' => $issue->id, 'requested_by' => $this->staff->id, 'new_due' => '2026-12-10', 'reason' => 'test']);
    ($this->board)($this->pm, ['tab' => 'team'])->assertSeeText('customer-intake')->assertSee('#due-review-'.$request);
    ($this->board)($this->staff, ['tab' => 'team'])->assertDontSeeText('due-approval')->assertDontSeeText('customer-intake');
    $ticket->update(['edit_lock_user_id' => $this->client->id, 'edit_lock_expires_at' => now()->addHour()]);
    ($this->board)($this->pm, ['tab' => 'team'])->assertDontSeeText('customer-intake');
    $ticket->update(['accepted_at' => now(), 'status' => 'in_progress', 'accepted_by' => $this->pm->id, 'needs_pm_action' => true]);
    ($this->board)($this->pm)->assertSeeText('customer-intake');
    ($this->board)($this->pm, ['tab' => 'team'])->assertDontSeeText('customer-intake');
    DB::table('ticket_needinfo')->insert(['ticket_id' => $ticket->id, 'note' => 'More info', 'requested_by' => $this->pm->id, 'requested_at' => now()]);
    ($this->board)($this->client)->assertSeeText('補充客戶資料');
    $ticket->update(['status' => 'pending_close']);
    ($this->board)($this->client)->assertSeeText('確認結案')->assertViewHas('counts', fn ($c) => $c['todo'] === 1);
    $ticket->update(['status' => 'closed']);
    ($this->board)($this->client)->assertDontSeeText('customer-intake');
});

it('sorts assigned dates in Taiwan time, paginates counts and combines roles', function () {
    $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 11, 28)->setTime(16, 30));
    foreach (['overdue-item' => '2026-11-28', 'today-item' => '2026-11-29', 'later-item' => '2026-12-01', 'undated-item' => null] as $title => $date) {
        wbIssue($this->project, $this->pm, $title, ['assignee_dev_id' => $this->staff->id, 'current_due' => $date]);
    }
    wbIssue($this->project, $this->pm, 'personal-test', ['assignee_test_id' => $this->staff->id, 'stage' => 'TEST', 'status' => 'test_pending']);
    wbIssue($this->project, $this->pm, 'scheduled-release', ['assignee_test_id' => $this->staff->id, 'stage' => 'RELEASE', 'status' => 'release_pending', 'release_planned_at' => '2026-12-06']);
    ($this->board)($this->staff)->assertSeeInOrder(['overdue-item', '已逾期', 'today-item', '今天', 'later-item', 'undated-item', 'personal-test'])
        ->assertDontSeeText('scheduled-release');
    ($this->board)($this->staff, ['tab' => 'schedule'])->assertSeeText('scheduled-release')->assertDontSeeText('undated-item');
    foreach (range(1, 20) as $n) {
        wbIssue($this->project, $this->pm, 'more-'.$n, ['assignee_dev_id' => $this->staff->id]);
    }
    ($this->board)($this->staff, ['tab' => 'todo', 'page' => 2])->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && count($rows) === 5);
});

it('shows only permitted watches and personal saved queries and orders by the latest event', function () {
    $issue = wbIssue($this->project, $this->pm, 'watched-internal');
    $ticket = wbTicket($this->project, $this->client, 'watched-customer', 'closed');
    foreach ([$this->client, $this->staff] as $u) {
        DB::table('ticket_watchers')->insert(['ticket_id' => $ticket->id, 'user_id' => $u->id, 'created_at' => now()]);
        DB::table('issue_watchers')->insert(['issue_id' => $issue->id, 'user_id' => $u->id, 'created_at' => now()]);
    }
    ActivityEvent::create(['project_id' => $this->project->id, 'subject_type' => 'ticket', 'subject_id' => $ticket->id, 'event_type' => 'comment', 'created_at' => now()->addMinute(), 'actor_id' => $this->pm->id]);
    app(SavedQueryService::class)->save($this->staff, $this->project->id, 'dev', 'my-dev-filter', ['mine' => 1]);
    ($this->board)($this->staff, ['tab' => 'watch'])->assertSeeInOrder(['watched-customer', 'watched-internal'])->assertSeeText('my-dev-filter')
        ->assertSee(route('dev.index', ['mine' => 1]))->assertViewHas('counts', fn ($c) => $c['todo'] === 0 && $c['watch'] === 2);
    ($this->board)($this->client, ['tab' => 'watch'])->assertSeeText('watched-customer')->assertDontSeeText('watched-internal')->assertDontSeeText('my-dev-filter');
    $viewer = wbMember($this->project, 'readonly', ['client_view'], 'client');
    wbTicket($this->project, $viewer, 'readonly-confirm', 'pending_close');
    ($this->board)($viewer)->assertViewHas('counts', fn ($c) => array_sum($c) === 0)->assertDontSeeText('readonly-confirm');
});
