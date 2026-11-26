<?php

use App\Models\ClientTicket;
use App\Models\User;
use App\Models\WorkflowStatus;
use App\Repositories\EventRepository;
use App\Repositories\IssueRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingRepository;
use App\Repositories\TicketRepository;
use App\Repositories\UserRepository;
use App\Repositories\WatchRepository;
use App\Services\Collab\WatchService;
use App\Services\SettlementService;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;

afterEach(fn () => Mockery::close());

it('only notifies internal recipients when all linked issues are excluded', function () {
    $ticket = (new ClientTicket)->setRawAttributes([
        'id' => 10, 'project_id' => 20, 'ticket_no' => 'CL-10', 'status' => 'in_progress', 'needs_pm_action' => false,
    ]);
    $client = (new User)->setRawAttributes(['id' => 30, 'user_type' => 'client']);
    $internal = (new User)->setRawAttributes(['id' => 40, 'user_type' => 'internal']);
    $pm = (new User)->setRawAttributes(['id' => 60, 'user_type' => 'internal']);
    $nonMember = (new User)->setRawAttributes(['id' => 80, 'user_type' => 'internal']);

    $subscriptions = Mockery::mock(WatchRepository::class);
    $subscriptions->shouldReceive('watchers')->with('ticket', 10)->twice()->andReturn(new Collection([$client, $internal]));

    $projects = Mockery::mock(ProjectRepository::class);
    $projects->shouldReceive('isMember')->with(20, 40)->twice()->andReturn(true);
    $projects->shouldReceive('isMember')->with(20, 30)->once()->andReturn(true);
    $projects->shouldReceive('isMember')->with(20, 60)->once()->andReturn(true);
    $projects->shouldReceive('isMember')->with(20, 80)->once()->andReturn(false);

    $users = Mockery::mock(UserRepository::class);
    $users->shouldReceive('notificationUsers')->with([60, 30, 80])->once()->andReturn(new Collection([$pm, $client, $nonMember]));

    $settings = Mockery::mock(SettingRepository::class);
    $settings->shouldReceive('int')->with('watch.max_recipients', 50)->twice()->andReturn(1);

    $workflow = Mockery::mock(WorkflowService::class);
    $workflow->shouldReceive('statusMap')->with('issue')->once()->andReturn(new Collection(['cancelled' => (object) ['is_excluded' => true]]));
    $workflow->shouldReceive('status')->with('ticket', 'in_progress')->once()->andReturn((new WorkflowStatus)->setRawAttributes(['is_terminal' => false]));
    $workflow->shouldReceive('setting')->with('mail.enabled', '0')->andReturn('1');

    $tickets = Mockery::mock(TicketRepository::class);
    $tickets->shouldReceive('ticketsOfIssue')->with(50, true)->once()->andReturn(new Collection([$ticket]));
    $tickets->shouldReceive('save')->with($ticket, ['needs_pm_action' => true])->once()->andReturn($ticket);

    $issues = Mockery::mock(IssueRepository::class);
    $issues->shouldReceive('issuesOfTicket')->with(10)->once()->andReturn(new Collection([(object) ['status' => 'cancelled']]));

    $events = Mockery::mock(EventRepository::class);
    $events->shouldReceive('userIdsWithPermission')->with(20, 'ticket.settle')->once()->andReturn([60, 70, 30, 80]);
    $events->shouldReceive('notify')->with([60, 40], 20, 'CL-10 的內部單都已取消，需要 PM 處置', '/pm/tickets/10', true)->once();
    $events->shouldReceive('notify')->with([60], 20, 'CL-10 有新留言', '/pm/tickets/10', true)->once();
    $events->shouldReceive('notify')->with([30], 20, 'CL-10 有新留言', '/tickets/10', true)->once();

    $watch = new WatchService($subscriptions, $events, $projects, $settings, $workflow, $users);
    $settlement = new SettlementService($tickets, $issues, $events, $workflow, $watch);

    $settlement->recompute(50, 70);
    $watch->notifyTicket($ticket, 'CL-10 有新留言', 70, [60], []);
});
