<?php

use App\Exceptions\BusinessException;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Services\TicketService;
use Spatie\Permission\PermissionRegistrar;

function coreUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'MES', 'name' => 'MES', 'ticket_prefix' => 'MES']);
    $this->client = coreUser('cpm', 'client', ['client_pm'], $this->p);
    $this->viewer = coreUser('cview', 'client', ['client_view'], $this->p);
    $this->pm = coreUser('ipm', 'internal', ['internal_pm'], $this->p);
    $this->dev = coreUser('dev1', 'internal', ['dev'], $this->p);
    $this->tester = coreUser('test1', 'internal', ['test'], $this->p);
    $this->tester2 = coreUser('test2', 'internal', ['test'], $this->p);
    $this->ts = app(TicketService::class);
    $this->is = app(IssueService::class);
    $this->newTicket = fn (string $title = '按鈕錯位') => $this->ts->create($this->client, $this->p->id, [
        'ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => $title, 'content_html' => '<p>內容</p>',
    ]);
    // ticket -> accepted -> issue -> dispatched to dev
    $this->toDev = function (ClientTicket $t): Issue {
        $this->ts->accept($this->pm, $t->id, $this->p->id);
        $i = $this->is->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => $t->title, 'content_html' => '<p>x</p>', 'severity' => 3]);
        $this->is->transition($this->pm, $i->id, $this->p->id, 'dev_todo', ['assignee_dev_id' => $this->dev->id]);

        return $i->fresh();
    };
    $this->release = function (Issue $i): void {
        $pkg = $this->is->createPackage($this->dev, $this->p->id, 'v1.0.1');
        $this->is->transition($this->dev, $i->id, $this->p->id, 'dev_doing', ['planned_due' => '2026-12-01']);
        $this->is->transition($this->dev, $i->id, $this->p->id, 'test_pending', ['package_id' => $pkg, 'assignee_test_id' => $this->tester->id]);
        $this->is->transition($this->tester, $i->id, $this->p->id, 'test_doing');
        $this->is->transition($this->tester, $i->id, $this->p->id, 'release_pending', ['release_planned_at' => '2026-12-05']);
        $this->is->transition($this->tester, $i->id, $this->p->id, 'released');
    };
});

it('creates ticket with number, revision 1 and blocks view-only role', function () {
    $t = ($this->newTicket)();
    expect($t->ticket_no)->toMatch('/^MES-\d{4}-0001$/')->and($t->status)->toBe('submitted')->and($t->revisions()->count())->toBe(1);
    expect(fn () => $this->ts->create($this->viewer, $this->p->id, ['ticket_type' => 'anomaly', 'title' => 'x', 'content_html' => '<p>x</p>']))
        ->toThrow(BusinessException::class);
});

it('blocks PM accept while client holds the edit lock', function () {
    $t = ($this->newTicket)();
    $this->ts->startEdit($this->client, $t->id, $this->p->id);
    expect(fn () => $this->ts->accept($this->pm, $t->id, $this->p->id))->toThrow(BusinessException::class, '客戶正在編輯');
    $this->ts->releaseEdit($this->client, $t->id, $this->p->id);
    expect($this->ts->accept($this->pm, $t->id, $this->p->id)->status)->toBe('in_progress');
});

it('expired lock does not block accept', function () {
    $t = ($this->newTicket)();
    $this->ts->startEdit($this->client, $t->id, $this->p->id);
    $this->travel(10)->minutes();
    expect($this->ts->accept($this->pm, $t->id, $this->p->id)->status)->toBe('in_progress');
});

it('client edit after accept becomes a revision; optimistic lock rejects stale save', function () {
    $t = ($this->newTicket)();
    $this->ts->accept($this->pm, $t->id, $this->p->id);
    $t = $this->ts->startEdit($this->client, $t->id, $this->p->id);
    $this->ts->saveEdit($this->client, $t->id, $this->p->id, ['title' => '新標題', 'content_html' => '<p>v2</p>'], $t->row_version);
    $t->refresh();
    expect($t->current_rev)->toBe(2)->and($t->revisions()->count())->toBe(2);
    $this->ts->startEdit($this->client, $t->id, $this->p->id);
    expect(fn () => $this->ts->saveEdit($this->client, $t->id, $this->p->id, ['title' => 'x', 'content_html' => '<p>x</p>'], 1))
        ->toThrow(BusinessException::class, '已被其他人修改');
});

it('chain lock: dispatching any linked issue locks the ticket for edit, revise and cancel', function () {
    $t = ($this->newTicket)();
    ($this->toDev)($t);
    $t->refresh();
    expect($t->dispatched_at)->not->toBeNull();
    $ab = $this->ts->abilities($t, $this->client);
    expect($ab['edit'])->toBeFalse()->and($ab['cancel'])->toBeFalse()->and($ab['comment'])->toBeTrue();
    expect(fn () => $this->ts->startEdit($this->client, $t->id, $this->p->id))->toThrow(BusinessException::class, '已拋單');
    expect(fn () => $this->ts->cancel($this->client, $t->id, $this->p->id, 'x'))->toThrow(BusinessException::class);
});

it('cannot dispatch while client is revising', function () {
    $t = ($this->newTicket)();
    $this->ts->accept($this->pm, $t->id, $this->p->id);
    $i = $this->is->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => 't', 'content_html' => '<p>x</p>']);
    $this->ts->startEdit($this->client, $t->id, $this->p->id);
    // business rule: dispatch is refused while the client holds a valid edit lock, nothing is changed
    expect(fn () => $this->is->transition($this->pm, $i->id, $this->p->id, 'dev_todo', ['assignee_dev_id' => $this->dev->id]))
        ->toThrow(BusinessException::class, '客戶正在編輯');
    expect($i->fresh()->status)->toBe('new')->and($t->fresh()->dispatched_at)->toBeNull();
    // once the client releases the lock, dispatch goes through and the ticket is chain-locked
    $this->ts->releaseEdit($this->client, $t->id, $this->p->id);
    $this->is->transition($this->pm, $i->id, $this->p->id, 'dev_todo', ['assignee_dev_id' => $this->dev->id]);
    expect($t->fresh()->dispatched_at)->not->toBeNull();
});

it('full flow to pending close, then close', function () {
    $t = ($this->newTicket)();
    $i = ($this->toDev)($t);
    ($this->release)($i);
    expect($t->fresh()->status)->toBe('pending_close');
    expect($this->ts->close($this->client, $t->id, $this->p->id)->status)->toBe('closed');
});

it('test failure returns to dev and counts rework; due date needs approval to change', function () {
    $t = ($this->newTicket)();
    $i = ($this->toDev)($t);
    $pkg = $this->is->createPackage($this->dev, $this->p->id, 'v1');
    expect(fn () => $this->is->transition($this->dev, $i->id, $this->p->id, 'dev_doing'))->toThrow(BusinessException::class, 'planned_due');
    $this->is->transition($this->dev, $i->id, $this->p->id, 'dev_doing', ['planned_due' => '2026-12-01']);
    $this->is->transition($this->dev, $i->id, $this->p->id, 'test_pending', ['package_id' => $pkg, 'assignee_test_id' => $this->tester->id]);
    $this->is->transition($this->tester, $i->id, $this->p->id, 'test_doing');
    $this->is->transition($this->tester, $i->id, $this->p->id, 'test_failed');
    expect($i->fresh()->rework_count)->toBe(1)->and($i->fresh()->stage)->toBe('DEV');

    $this->is->requestDueChange($this->dev, $i->id, $this->p->id, '2026-12-20', '需求變更');
    expect($i->fresh()->current_due->toDateString())->toBe('2026-12-01');
    $req = $i->dueRequests()->first();
    expect(fn () => $this->is->reviewDueChange($this->dev, $req->id, $this->p->id, true))->toThrow(BusinessException::class);
    $this->is->reviewDueChange($this->pm, $req->id, $this->p->id, true);
    expect($i->fresh()->current_due->toDateString())->toBe('2026-12-20')->and($i->fresh()->planned_due->toDateString())->toBe('2026-12-01');
});

it('settlement excludes cancelled issues; all cancelled needs PM action', function () {
    $t = ($this->newTicket)();
    $this->ts->accept($this->pm, $t->id, $this->p->id);
    $a = $this->is->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => 'a', 'content_html' => '<p>a</p>']);
    $b = $this->is->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => 'b', 'content_html' => '<p>b</p>']);
    expect(fn () => $this->is->transition($this->pm, $b->id, $this->p->id, 'wont_fix', ['cancel_reason' => 'dup']))
        ->toThrow(BusinessException::class, '給客戶看的說明');
    $this->is->transition($this->pm, $b->id, $this->p->id, 'wont_fix', ['cancel_reason' => 'dup', 'client_note' => '與另一項合併處理']);
    $this->is->transition($this->pm, $a->id, $this->p->id, 'dev_todo', ['assignee_dev_id' => $this->dev->id]);
    ($this->release)($a->fresh());
    expect($t->fresh()->status)->toBe('pending_close');

    $t2 = ($this->newTicket)('第二張');
    $this->ts->accept($this->pm, $t2->id, $this->p->id);
    $c = $this->is->createFromTickets($this->pm, $this->p->id, [$t2->id], ['title' => 'c', 'content_html' => '<p>c</p>']);
    $this->is->transition($this->pm, $c->id, $this->p->id, 'cancelled', ['cancel_reason' => 'no', 'client_note' => '無法重現']);
    expect($t2->fresh()->needs_pm_action)->toBeTrue()->and($t2->fresh()->status)->toBe('in_progress');
    $this->ts->settle($this->pm, $t2->id, $this->p->id, 'pending', '經確認無法重現，請確認是否結案');
    expect($t2->fresh()->status)->toBe('pending_close')->and($t2->fresh()->needs_pm_action)->toBeFalse();
});

it('reopen flags PM action; a new linked issue keeps ticket in progress until released', function () {
    $t = ($this->newTicket)();
    $i = ($this->toDev)($t);
    ($this->release)($i);
    $this->ts->reopen($this->client, $t->id, $this->p->id, '<p>還是有問題</p>');
    $t->refresh();
    expect($t->status)->toBe('in_progress')->and($t->needs_pm_action)->toBeTrue()->and($t->reopen_count)->toBe(1);
    $n = $this->is->createFromTickets($this->pm, $this->p->id, [$t->id], ['title' => 'fix again', 'content_html' => '<p>x</p>']);
    expect($t->fresh()->needs_pm_action)->toBeFalse()->and($i->fresh()->status)->toBe('released');
    $this->is->transition($this->pm, $n->id, $this->p->id, 'dev_todo', ['assignee_dev_id' => $this->dev->id]);
    expect($t->fresh()->status)->toBe('in_progress');
    ($this->release)($n->fresh());
    expect($t->fresh()->status)->toBe('pending_close');
});

it('client cancel before dispatch cancels exclusive PM issues and unlinks shared ones', function () {
    $t1 = ($this->newTicket)('一');
    $t2 = ($this->newTicket)('二');
    $this->ts->accept($this->pm, $t1->id, $this->p->id);
    $this->ts->accept($this->pm, $t2->id, $this->p->id);
    $own = $this->is->createFromTickets($this->pm, $this->p->id, [$t1->id], ['title' => 'own', 'content_html' => '<p>x</p>']);
    $shared = $this->is->createFromTickets($this->pm, $this->p->id, [$t1->id, $t2->id], ['title' => 'shared', 'content_html' => '<p>x</p>']);
    $this->ts->cancel($this->client, $t1->id, $this->p->id, '不需要了');
    expect($t1->fresh()->status)->toBe('cancelled')
        ->and($own->fresh()->status)->toBe('cancelled')
        ->and($shared->fresh()->status)->toBe('new')
        ->and($shared->tickets()->pluck('client_tickets.id')->all())->toBe([$t2->id]);
});

it('test-created issue severity is editable only by its creator', function () {
    $i = $this->is->createInternal($this->tester, $this->p->id, 'test', ['title' => '新發現', 'content_html' => '<p>x</p>', 'severity' => 4, 'assignee_dev_id' => $this->dev->id]);
    expect($i->status)->toBe('test_found')->and($i->stage)->toBe('DEV');
    $this->is->updateFields($this->tester, $i->id, $this->p->id, ['severity' => 5]);
    expect($i->fresh()->severity)->toBe(5);
    expect(fn () => $this->is->updateFields($this->pm, $i->id, $this->p->id, ['severity' => 1]))->toThrow(BusinessException::class);
    expect(fn () => $this->is->updateFields($this->tester2, $i->id, $this->p->id, ['severity' => 1]))->toThrow(BusinessException::class);
});

it('locks issue body after dispatch and allows addendum', function () {
    $t = ($this->newTicket)();
    $i = ($this->toDev)($t);
    expect(fn () => $this->is->updateFields($this->pm, $i->id, $this->p->id, ['title' => 'x']))->toThrow(BusinessException::class);
    $this->is->addAddendum($this->pm, $i->id, $this->p->id, '<p>補充</p>');
    expect($i->addenda()->count())->toBe(1);
});

it('client comment after dispatch is allowed and visible on linked issue side', function () {
    $t = ($this->newTicket)();
    ($this->toDev)($t);
    $this->ts->addComment($this->client, $t->id, $this->p->id, 'supplement', '<p>補充截圖</p>');
    expect($t->comments()->count())->toBe(1);
    expect(fn () => $this->ts->addComment($this->viewer, $t->id, $this->p->id, 'supplement', '<p>x</p>'))->toThrow(BusinessException::class);
});
