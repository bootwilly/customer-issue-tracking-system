<?php

use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Repositories\UnitOfWork;
use App\Services\NumberingService;

// Numbers are unique system-wide while counters are per project: a prefix taken over from another project skips used numbers.

it('skips issue and ticket numbers already used by a project that had the prefix before', function () {
    $old = Project::create(['code' => 'OLD', 'name' => 'old', 'ticket_prefix' => 'Test']);
    $new = Project::create(['code' => 'NEW', 'name' => 'new', 'ticket_prefix' => 'YC']);
    $u = User::create(['account' => 'nu', 'name' => 'nu', 'email' => 'nu@t.test', 'password' => '123456', 'user_type' => 'internal', 'status' => 'active']);
    $year = now('Asia/Taipei')->format('Y');
    foreach ([2, 3] as $n) {
        Issue::create(['project_id' => $old->id, 'issue_no' => "YC-I{$year}-000{$n}", 'origin' => 'pm', 'title' => 't', 'content_html' => '<p>x</p>', 'stage' => 'PM', 'status' => 'new', 'created_by' => $u->id]);
    }
    Issue::withoutEvents(fn () => Issue::create(['project_id' => $old->id, 'issue_no' => "YC-I{$year}-0004", 'origin' => 'pm', 'title' => 't', 'content_html' => '<p>x</p>', 'stage' => 'PM', 'status' => 'new', 'created_by' => $u->id]))->delete(); // in the recycle bin: still taken
    ClientTicket::create(['project_id' => $old->id, 'ticket_no' => "YC-{$year}-0001", 'ticket_type' => 'anomaly', 'title' => 't', 'status' => 'submitted', 'content_html' => '<p>c</p>', 'created_by' => $u->id, 'current_rev' => 1]);

    $next = fn (string $kind) => app(UnitOfWork::class)->run(fn () => app(NumberingService::class)->next($new->id, $kind));

    expect([$next('issue'), $next('issue'), $next('issue')])->toBe(["YC-I{$year}-0001", "YC-I{$year}-0005", "YC-I{$year}-0006"])
        ->and($next('ticket'))->toBe("YC-{$year}-0002")
        ->and($next('issue'))->toBe("YC-I{$year}-0007");
});
