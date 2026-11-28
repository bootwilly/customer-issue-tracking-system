<?php

use App\Models\ActivityEvent;
use App\Models\ClientTicket;
use App\Models\DueChangeRequest;
use App\Models\Issue;
use App\Models\IssueCloseTask;
use App\Models\IssueDependency;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;
use App\Repositories\IssueRepository;
use App\Services\Collab\DependencyService;
use App\Services\IssueCloseService;
use App\Services\WorkflowService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->withoutVite();
    $this->p = Project::create(['code' => 'CL', 'name' => 'Close', 'ticket_prefix' => 'CL']);
    foreach (['pm' => 'internal_pm', 'dev' => 'dev', 'tester' => 'test', 'client' => 'client_pm', 'manager' => 'project_admin'] as $key => $role) {
        $user = User::create(['name' => $key, 'account' => $key, 'email' => $key.'@example.test', 'password' => 'example123',
            'user_type' => $key === 'client' ? 'client' : 'internal', 'status' => 'active']);
        $this->p->members()->attach($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->p->id);
        $user->assignRole($role);
        $this->{$key} = $user;
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->as = fn ($u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->issue = fn ($status = 'new', $extra = []) => Issue::create($extra + ['project_id' => $this->p->id,
        'issue_no' => 'CL-'.(Issue::withTrashed()->count() + 1), 'origin' => 'pm', 'status' => $status,
        'stage' => app(WorkflowService::class)->status('issue', $status)->stage, 'title' => '內部測試項目',
        'content_html' => '<p>test</p>', 'created_by' => $this->pm->id]);
    $this->requestClose = fn ($i, $data = []) => ($this->as)($this->pm)->post(route('issues.transition', $i), $data + ['to' => 'closed_pm', 'close_reason' => '內部結案原因']);
    $this->finish = fn ($u, $i, $t, $note = '收尾完成') => ($this->as)($u)->post(route('issues.close-tasks.finish', [$i, $t]), ['note' => $note]);
    $this->events = fn ($i) => ActivityEvent::where(['subject_type' => 'issue', 'subject_id' => $i->id])->orderBy('id')->pluck('event_type')->all();
    $this->meeting = function ($user) {
        ($this->as)($user)->post(route('meetings.store'), ['type' => 'regular', 'title' => '測試會議', 'start_at' => '2026-12-01 10:00', 'mode' => 'scheduled'])->assertSessionHasNoErrors();

        return Meeting::latest('id')->firstOrFail();
    };
});

it('closes directly without wrap-up and records the requestor and terminal time', function ($status) {
    $i = ($this->issue)($status);
    ($this->as)($this->pm)->get(route('issues.show', $i))->assertOk()->assertSee('沒有進行中的工作')->assertSee('PM結案');
    ($this->requestClose)($i)->assertSessionHas('ok');
    expect($i->refresh()->status)->toBe('closed_pm')->and($i->stage)->toBeNull()->and($i->closed_by)->toBe($this->pm->id)
        ->and($i->closed_at)->not->toBeNull()->and($i->close_reason)->toBe('內部結案原因')
        ->and($i->closeTasks)->toHaveCount(0)->and(($this->events)($i))->toContain('status');
    ($this->as)($this->pm)->get(route('issues.show', $i))->assertOk()->assertSee('結案方式／時間');
    ($this->requestClose)($i)->assertSessionHas('err');
    ($this->as)($this->pm)->delete(route('issues.destroy', $i))->assertSessionHas('err');
})->with(['new', 'discussing', 'dev_doing', 'test_doing']);

it('requires the assigned DEV, rejects duplicate requests and completes exactly once', function () {
    $i = ($this->issue)('dev_doing', ['assignee_dev_id' => $this->dev->id]);
    ($this->requestClose)($i)->assertSessionHas('ok');
    $task = $i->closeTasks()->sole();
    expect($i->refresh()->status)->toBe('closing')->and($task->user_id)->toBe($this->dev->id)->and($task->role)->toBe('dev');
    ($this->as)($this->dev)->get(route('dev.index'))->assertOk()->assertSee('待收尾')->assertSee('內部結案原因');
    ($this->as)($this->pm)->get(route('issues.show', $i))->assertOk()->assertSee('撤回結案')->assertDontSee('name="to"', false);
    ($this->requestClose)($i)->assertSessionHas('err');
    ($this->finish)($this->pm, $i, $task)->assertSessionHas('err');
    ($this->finish)($this->dev, $i, $task, '  ')->assertSessionHasErrors('note');
    $this->assertDatabaseHas('user_notifications', ['user_id' => $this->dev->id, 'url' => '/issues/'.$i->id, 'title' => $i->issue_no.' 已送出結案，請完成收尾']);
    $other = ($this->issue)();
    ($this->finish)($this->dev, $other, $task)->assertSessionHas('err');
    ($this->finish)($this->dev, $i, $task)->assertSessionHas('ok');
    ($this->finish)($this->dev, $i, $task)->assertSessionHas('err');
    ($this->as)($this->pm)->post(route('issues.close.withdraw', $i))->assertSessionHas('err');
    expect($i->refresh()->status)->toBe('closed_pm')->and($task->refresh()->note)->toBe('收尾完成')
        ->and(($this->events)($i))->toBe(['close_requested', 'close_task_done', 'status', 'close_completed']);
});

it('serializes the last two confirmations and keeps the PM as the initiating actor', function () {
    $i = ($this->issue)('test_doing', ['assignee_dev_id' => $this->dev->id, 'assignee_test_id' => $this->tester->id]);
    ($this->requestClose)($i)->assertSessionHas('ok');
    $tasks = $i->closeTasks()->get()->keyBy('role');
    expect($tasks)->toHaveCount(2);
    ($this->finish)($this->dev, $i, $tasks['dev'])->assertSessionHas('ok');
    expect($i->refresh()->status)->toBe('closing');
    ($this->as)($this->tester)->get(route('test.index'))->assertOk()->assertSee('待收尾')->assertSee('內部結案原因');
    ($this->finish)($this->tester, $i, $tasks['test'])->assertSessionHas('ok');
    expect($i->refresh()->status)->toBe('closed_pm')->and($i->closed_by)->toBe($this->pm->id);
});

it('assigns release to the permission pool even without a tester', function ($assigned) {
    $i = ($this->issue)('release_pending', ['assignee_test_id' => $assigned ? $this->tester->id : null]);
    ($this->requestClose)($i)->assertSessionHas('ok');
    $tasks = $i->closeTasks()->get()->keyBy('role');
    expect($tasks)->toHaveCount($assigned ? 2 : 1)->and($tasks['release']->user_id)->toBeNull();
    ($this->as)($this->tester)->get(route('test.index'))->assertSee('待收尾');
    ($this->finish)($this->pm, $i, $tasks['release'])->assertSessionHas('err');
    ($this->finish)($this->tester, $i, $tasks['release'])->assertSessionHas('ok');
    if ($assigned) {
        expect($i->refresh()->status)->toBe('closing');
        ($this->finish)($this->tester, $i, $tasks['test'])->assertSessionHas('ok');
    }
    expect($i->refresh()->status)->toBe('closed_pm');
})->with([true, false]);

it('withdraws only pending work and rejects stale confirmations across rounds', function () {
    $i = ($this->issue)('test_doing', ['assignee_dev_id' => $this->dev->id, 'assignee_test_id' => $this->tester->id]);
    ($this->requestClose)($i)->assertSessionHas('ok');
    $tasks = $i->closeTasks()->get()->keyBy('role');
    ($this->finish)($this->dev, $i, $tasks['dev'])->assertSessionHas('ok');
    ($this->as)($this->pm)->post(route('issues.close.withdraw', $i), ['to' => 'new'])->assertSessionHas('ok');
    expect($i->refresh()->status)->toBe('test_doing')->and($i->stage)->toBe('TEST')->and($i->close_target)->toBeNull()
        ->and($tasks['dev']->refresh()->status)->toBe('done')->and($tasks['test']->refresh()->status)->toBe('void');
    ($this->requestClose)($i)->assertSessionHas('ok');
    ($this->finish)($this->tester, $i, $tasks['test'])->assertSessionHas('err');
    expect($i->closeTasks()->where(['round' => 2, 'status' => 'pending'])->count())->toBe(2);
});

it('limits closing to internal PMs and rejects the meeting target on the issue endpoint', function () {
    $i = ($this->issue)();
    foreach ([$this->dev, $this->tester] as $u) {
        ($this->as)($u)->get(route('issues.show', $i))->assertDontSee('PM結案');
        ($this->as)($u)->post(route('issues.transition', $i), ['to' => 'closed_pm', 'close_reason' => 'x'])->assertSessionHas('err');
    }
    ($this->as)($this->client)->get(route('issues.show', $i))->assertForbidden();
    ($this->as)($this->client)->post(route('issues.transition', $i), ['to' => 'closed_pm'])->assertForbidden();
    ($this->requestClose)($i, ['to' => 'closed_meeting'])->assertSessionHas('err');
    ($this->requestClose)($i, ['close_reason' => '  '])->assertSessionHas('err');
    expect($i->refresh()->status)->toBe('new');
});

it('settles a mix of released and closed issues and shows only the public note on the ticket', function () {
    $i = ($this->issue)();
    $other = ($this->issue)('released');
    $ticket = ClientTicket::create(['project_id' => $this->p->id, 'ticket_no' => 'C-1', 'ticket_type' => 'anomaly', 'title' => '客戶問題',
        'status' => 'in_progress', 'content_html' => 'c', 'created_by' => $this->client->id, 'current_rev' => 1, 'accepted_at' => now()]);
    $ticket->issues()->attach([$i->id, $other->id], ['linked_by' => $this->pm->id, 'based_on_rev' => 1, 'linked_at' => now()]);
    ($this->requestClose)($i)->assertSessionHas('err', '此內部單有連結客戶單，請填寫給客戶看的說明。');
    ($this->requestClose)($i, ['client_note' => '客戶處理說明內容'])->assertSessionHas('ok');
    expect($ticket->refresh()->status)->toBe('pending_close');
    ($this->as)($this->client)->get(route('tickets.show', $ticket))->assertOk()->assertSee('客戶處理說明內容')->assertDontSee('內部結案原因')->assertDontSee($i->issue_no);
});

it('retains frozen package membership and detaches open packages at completion', function ($frozen) {
    $pkg = app(IssueRepository::class)->createPackage($this->p->id, 'package', $this->pm->id);
    $i = ($this->issue)('dev_doing', ['assignee_dev_id' => $this->dev->id, 'package_id' => $pkg->id]);
    if ($frozen) {
        $released = ($this->issue)('released', ['package_id' => $pkg->id]);
        ($this->requestClose)($released)->assertSessionHas('err');
    }
    ($this->requestClose)($i)->assertSessionHas('ok');
    expect($i->refresh()->package_id)->toBe($pkg->id);
    ($this->finish)($this->dev, $i, $i->closeTasks()->sole())->assertSessionHas('ok');
    expect($i->refresh()->package_id)->toBe($frozen ? $pkg->id : null);
})->with([true, false]);

it('unblocks dependencies only when the upstream work is finished', function () {
    $up = ($this->issue)('dev_doing', ['assignee_dev_id' => $this->dev->id]);
    $down = ($this->issue)();
    IssueDependency::create(['issue_id' => $down->id, 'depends_on_id' => $up->id, 'kind' => 'blocks', 'created_by' => $this->pm->id]);
    $blockers = fn () => app(DependencyService::class)->blockers([$down->id])[$down->id] ?? [];
    ($this->requestClose)($up)->assertSessionHas('ok');
    expect($blockers())->toContain($up->issue_no);
    ($this->finish)($this->dev, $up, $up->closeTasks()->sole())->assertSessionHas('ok');
    expect($blockers())->toBe([]);
});

it('rejects pending extensions while preserving the original request reason', function () {
    $i = ($this->issue)('dev_doing', ['assignee_dev_id' => $this->dev->id, 'current_due' => '2026-12-01']);
    $due = DueChangeRequest::create(['issue_id' => $i->id, 'requested_by' => $this->dev->id, 'old_due' => '2026-12-01', 'new_due' => '2026-12-05', 'reason' => '原始申請']);
    ($this->requestClose)($i)->assertSessionHas('ok');
    expect($due->refresh()->status)->toBe('rejected')->and($due->reviewed_by)->toBe($this->pm->id)->and($due->reason)->toBe('原始申請')
        ->and(ActivityEvent::where('event_type', 'due_review')->sole()->payload['note'])->toContain('內部單結案');
});

it('closes from a meeting conclusion and returns the updated item and badge', function ($wrap) {
    $m = ($this->meeting)($this->pm);
    $i = ($this->issue)($wrap ? 'test_doing' : 'new', ['assignee_dev_id' => $this->dev->id]);
    $r = ($this->as)($this->pm)->postJson(route('meetings.items.store', $m), ['topic' => '事項', 'issue_no' => $i->issue_no]);
    $r->assertOk()->assertJsonPath('item.can_close_issue', true)->assertJsonPath('item.issue_closing', false);
    $item = $r->json('item.id');
    $path = route('meetings.items.close-issue', [$m, $item]);
    ($this->as)($this->pm)->postJson($path)->assertUnprocessable()->assertJsonPath('error', '請先填寫結論再結案');
    ($this->as)($this->pm)->putJson(route('meetings.items.update', [$m, $item]), ['conclusion' => '已討論確認'])->assertOk();
    ($this->as)($this->dev)->postJson($path)->assertUnprocessable();
    ($this->as)($this->pm)->postJson($path)->assertOk()->assertJsonPath('item.can_close_issue', false)
        ->assertJsonPath('item.issue_closing', $wrap)->assertJsonPath('item.issue_closed', ! $wrap)->assertJsonPath('item.status', 'done');
    expect($i->refresh()->status)->toBe($wrap ? 'closing' : 'closed_meeting')
        ->and($i->close_meeting_item_id)->toBe($item)->and($i->close_reason)->toBe('已討論確認');
    if ($wrap) {
        expect($i->close_target)->toBe('closed_meeting');
        ($this->as)($this->pm)->postJson($path)->assertUnprocessable();
        ($this->finish)($this->dev, $i, $i->closeTasks()->sole())->assertSessionHas('ok');
    }
    expect($i->refresh()->status)->toBe('closed_meeting')
        ->and(ActivityEvent::where('event_type', 'closed_by_meeting')->sole()->payload)->toMatchArray(['meeting' => $m->title, 'meeting_id' => $m->id, 'item_id' => $item]);
    ($this->as)($this->pm)->postJson($path)->assertUnprocessable();
})->with([true, false]);

it('hides internal close fields from client HTML and JSON and refuses trashed issues', function () {
    $m = ($this->meeting)($this->client);
    $i = ($this->issue)();
    $item = ($this->as)($this->pm)->postJson(route('meetings.items.store', $m), ['topic' => '事項', 'issue_no' => $i->issue_no, 'conclusion' => '結論'])->json('item.id');
    ($this->as)($this->client)->get(route('meetings.show', $m))->assertOk()->assertDontSee('會議討論結案');
    ($this->as)($this->client)->putJson(route('meetings.items.update', [$m, $item]), ['topic' => '更新後'])
        ->assertOk()->assertJsonMissingPath('item.can_close_issue')->assertJsonMissingPath('item.issue_closing');
    ($this->as)($this->client)->postJson(route('meetings.items.close-issue', [$m, $item]))->assertUnprocessable();
    ($this->as)($this->pm)->delete(route('issues.destroy', $i))->assertSessionHas('ok');
    ($this->as)($this->pm)->postJson(route('meetings.items.close-issue', [$m, $item]))->assertUnprocessable();
});

it('purges historical wrap-up rows before permanently deleting the issue', function () {
    $i = ($this->issue)();
    $t = $i->closeTasks()->create(['round' => 1, 'role' => 'dev', 'user_id' => $this->dev->id, 'status' => 'void']);
    ($this->as)($this->pm)->delete(route('issues.destroy', $i))->assertSessionHas('ok');
    ($this->as)($this->manager)->delete(route('padmin.trash.issues.purge', $i->id), ['confirm_no' => $i->issue_no])->assertSessionHas('ok');
    expect(Issue::withTrashed()->find($i->id))->toBeNull()->and(IssueCloseTask::find($t->id))->toBeNull();
});

it('takes the issue write lock inside every transaction and locks the task after its issue', function () {
    $i = ($this->issue)('dev_doing', ['assignee_dev_id' => $this->dev->id]);
    $db = DB::connection();
    $original = $db->getQueryGrammar();
    // SQLite ignores FOR UPDATE. Observe the same builder lock flag that the MariaDB grammar compiles.
    $probe = new class($db) extends SQLiteGrammar
    {
        public array $locks = [];

        protected function compileLock(Builder $query, $value)
        {
            if ($value === true) {
                $this->locks[] = [$query->from, $query->getConnection()->transactionLevel()];
            }

            return parent::compileLock($query, $value);
        }
    };
    $db->setQueryGrammar($probe);
    $level = $db->transactionLevel() + 1;
    try {
        ($this->requestClose)($i)->assertSessionHas('ok');
        expect($probe->locks[0])->toBe(['issues', $level]);
        $probe->locks = [];
        ($this->as)($this->pm)->post(route('issues.close.withdraw', $i))->assertSessionHas('ok');
        expect($probe->locks[0])->toBe(['issues', $level]);
        ($this->requestClose)($i)->assertSessionHas('ok');
        $task = $i->closeTasks()->where('round', 2)->sole();
        $probe->locks = [];
        ($this->finish)($this->dev, $i, $task)->assertSessionHas('ok');
        expect(array_slice($probe->locks, 0, 2))->toBe([['issues', $level], ['issue_close_tasks', $level]]);
    } finally {
        $db->setQueryGrammar($original);
    }
});

it('requires a separate public explanation from meetings and keeps unsatisfied tickets in progress', function () {
    $m = ($this->meeting)($this->pm);
    $i = ($this->issue)('dev_doing', ['assignee_dev_id' => $this->dev->id]);
    $open = ($this->issue)();
    $ticket = ClientTicket::create(['project_id' => $this->p->id, 'ticket_no' => 'C-2', 'ticket_type' => 'anomaly', 'title' => '申請',
        'status' => 'in_progress', 'content_html' => 'c', 'created_by' => $this->client->id, 'current_rev' => 1, 'accepted_at' => now()]);
    $ticket->issues()->attach([$i->id, $open->id], ['linked_by' => $this->pm->id, 'based_on_rev' => 1, 'linked_at' => now()]);
    $result = ($this->as)($this->pm)->postJson(route('meetings.items.store', $m), ['topic' => '修改', 'issue_no' => $i->issue_no, 'conclusion' => '內部摘要']);
    $result->assertJsonPath('item.needs_client_note', true);
    $url = route('meetings.items.close-issue', [$m, $result->json('item.id')]);
    ($this->as)($this->pm)->postJson($url)->assertUnprocessable();
    expect($m->items()->sole()->status)->toBe('open')->and($i->refresh()->status)->toBe('dev_doing');
    ($this->as)($this->pm)->postJson($url, ['client_note' => '對外說明'])->assertOk();
    $this->manager->forceFill(['is_admin' => true])->save();
    $closed = app(IssueCloseService::class)->finishTask($this->manager, $this->p->id, $i->closeTasks()->sole()->id, '已代為確認');
    expect($closed->status)->toBe('closed_meeting');
    expect($ticket->refresh()->status)->toBe('in_progress');
    ($this->as)($this->client)->get(route('tickets.show', $ticket))->assertSee('對外說明')->assertDontSee('內部摘要');
});
