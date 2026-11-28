<?php

use App\Models\ActivityEvent;
use App\Models\Attachment;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\LookupItem;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;
use App\Repositories\IssueRepository;
use App\Repositories\SettingRepository;
use App\Services\Dev\DevService;
use App\Services\Export\IssueExportService;
use App\Services\IssueCloseService;
use App\Services\IssueService;
use App\Services\Pm\PmQueryService;
use App\Services\Test\TestService;
use App\Services\TicketService;
use App\Services\WorkflowService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function ieUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "{$account}@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function ieIssue(User $by, Project $p, string $origin, array $over = []): Issue
{
    $data = ['title' => $over['title'] ?? '項目', 'content_html' => $over['content_html'] ?? '<p>x</p>', 'severity' => 2];
    if ($origin === 'test') {
        $data['assignee_dev_id'] = $over['assignee_dev_id'];
    }
    $i = app(IssueService::class)->createInternal($by, $p->id, $origin, $data);
    unset($over['title'], $over['content_html']);
    if ($over) {
        $i->forceFill($over)->save();
    }

    return $i->fresh();
}

function ieXml(mixed $r, string $entry): string
{
    $path = is_string($r) ? $r : $r->baseResponse->getFile()->getPathname();
    $z = new ZipArchive;
    $z->open($path);
    $s = (string) $z->getFromName($entry);
    $z->close();

    return $s;
}

/** Concatenated plain text of every inlineStr cell. */
function ieText(string $xml): string
{
    preg_match_all('~<t[^>]*>(.*?)</t>~s', $xml, $m);

    return html_entity_decode(implode("\n", $m[1]), ENT_QUOTES | ENT_XML1);
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'MES', 'name' => 'MES', 'ticket_prefix' => 'MES']);
    $this->pm = ieUser('iepm', 'internal', ['internal_pm'], $this->p);
    $this->dev = ieUser('iedev', 'internal', ['dev'], $this->p);
    $this->dev2 = ieUser('iedev2', 'internal', ['dev'], $this->p);
    $this->tester = ieUser('ietest', 'internal', ['test'], $this->p);
    $this->client = ieUser('ieclient', 'client', ['client_pm'], $this->p);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->svc = app(IssueExportService::class);
    $this->nos = fn ($sheet) => array_column($sheet->rows, 'issue_no');
    $this->listed = fn (array $f) => app(IssueRepository::class)->paginate($this->p->id, $f, 100)->pluck('issue_no')->all();
});

it('exports exactly the rows the dev list shows for several filter combos', function () {
    ieIssue($this->dev, $this->p, 'dev', ['title' => '我的逾期', 'assignee_dev_id' => $this->dev->id, 'current_due' => '2020-01-01']);
    ieIssue($this->dev2, $this->p, 'dev', ['title' => '別人的', 'assignee_dev_id' => $this->dev2->id]);
    ieIssue($this->dev, $this->p, 'dev', ['title' => '未來的', 'assignee_dev_id' => $this->dev->id, 'current_due' => '2099-01-01']);
    ieIssue($this->dev, $this->p, 'dev', ['title' => '已完成', 'assignee_dev_id' => $this->dev->id, 'status' => 'released']);

    foreach ([
        ['tab' => 'active'], ['tab' => 'active', 'f' => 1], ['tab' => 'all', 'f' => 1], ['tab' => 'all', 'f' => 1, 'mine' => 1],
        ['tab' => 'all', 'f' => 1, 'status' => 'dev_todo'], ['tab' => 'active', 'f' => 1, 'overdue' => 1], ['tab' => 'all', 'f' => 1, 'q' => '未來'],
    ] as $in) {
        $fl = app(DevService::class)->filters($this->dev, $this->p->id, $in);
        $sheet = $this->svc->issueSheet('dev_issues', $this->p->id, $fl['f']);
        expect(($this->nos)($sheet))->toBe(($this->listed)($fl['f']), json_encode($in))
            ->and(count($sheet->details))->toBe(count($sheet->rows));
    }
    $fl = app(DevService::class)->filters($this->dev, $this->p->id, ['tab' => 'all', 'f' => 1, 'q' => '未來']);
    expect(($this->nos)($this->svc->issueSheet('dev_issues', $this->p->id, $fl['f'])))->toHaveCount(1);
});

it('exports exactly the rows the test list shows', function () {
    $a = ieIssue($this->tester, $this->p, 'test', ['title' => '測試甲', 'assignee_dev_id' => $this->dev->id, 'assignee_test_id' => $this->tester->id]);
    ieIssue($this->tester, $this->p, 'test', ['title' => '測試乙', 'assignee_dev_id' => $this->dev->id, 'status' => 'test_doing']);
    ieIssue($this->dev, $this->p, 'dev', ['title' => '開發中', 'assignee_dev_id' => $this->dev->id, 'status' => 'test_doing']);

    foreach ([['tab' => 'active'], ['tab' => 'bounced'], ['tab' => 'active', 'status' => 'test_doing'], ['tab' => 'active', 'mine' => 1], ['tab' => 'active', 'q' => '乙']] as $in) {
        $fl = app(TestService::class)->filters($this->tester, $this->p->id, $in);
        $sheet = $this->svc->issueSheet('test_issues', $this->p->id, $fl['f']);
        expect(($this->nos)($sheet))->toBe(($this->listed)($fl['f']), json_encode($in));
    }
    expect($a->id)->toBeInt();
});

it('exports exactly the rows the pm issue list shows', function () {
    ieIssue($this->dev, $this->p, 'dev', ['title' => 'PM甲', 'assignee_dev_id' => $this->dev->id]);
    ieIssue($this->tester, $this->p, 'test', ['title' => 'PM乙', 'assignee_dev_id' => $this->dev->id]);
    ieIssue($this->dev, $this->p, 'dev', ['title' => 'PM丙', 'assignee_dev_id' => $this->dev->id, 'current_due' => '2030-01-01']);

    foreach ([[], ['origin' => 'dev'], ['origin' => 'test'], ['q' => '乙'], ['sort' => 'due'], ['status' => 'dev_todo', 'sort' => 'severity']] as $in) {
        $f = app(PmQueryService::class)->issueFilters($in);
        $sheet = $this->svc->pmIssuesSheet($this->pm, $this->p->id, $in);
        expect(($this->nos)($sheet))->toBe(($this->listed)($f), json_encode($in))
            ->and($sheet->columns)->not->toHaveKey('week');
    }
});

it('adds the week column on the dev schedule export', function () {
    $tz = 'Asia/Taipei';
    ieIssue($this->dev, $this->p, 'dev', ['title' => '逾期單', 'assignee_dev_id' => $this->dev->id, 'current_due' => now($tz)->subDays(10)->toDateString()]);
    ieIssue($this->dev, $this->p, 'dev', ['title' => '之後單', 'assignee_dev_id' => $this->dev->id, 'current_due' => now($tz)->addWeeks(6)->toDateString()]);
    ieIssue($this->dev, $this->p, 'dev', ['title' => '無日期單', 'assignee_dev_id' => $this->dev->id]);

    $book = $this->svc->devBook($this->dev, $this->p->id, ['tab' => 'schedule', 'f' => 1, 'mine' => 1]);
    $s = $book->sheets[0];
    expect($s->name)->toBe('開發問題')->and($s->columns)->toHaveKey('week')->and(array_key_first($s->columns))->toBe('week');
    expect(array_column($s->rows, 'week'))->toBe(['已逾期', '之後'])->and(array_column($s->rows, 'title'))->toBe(['逾期單', '之後單']);
    expect($book->info['頁籤'])->toBe('排程清單');
});

it('shows the first PM dispatch in Taipei time on the four dev tabs and their Excel sheets', function ($source) {
    $pid = $this->p->id;
    $i = ieIssue($this->pm, $this->p, 'pm', ['status' => $source, 'current_due' => '2026-12-01']);
    $this->travelTo(now('UTC')->setDateTime(2026, 10, 4, 19, 15), function () use ($i, $pid) {
        app(IssueService::class)->transition($this->pm, $i->id, $pid, 'dev_todo', ['assignee_dev_id' => $this->dev->id]);
    });
    $other = ieIssue($this->dev, $this->p, 'dev', ['assignee_dev_id' => $this->dev->id, 'current_due' => '2026-12-01']);
    foreach ([
        [$i, 'issue', 'status', 'new', 'dev_todo', '2026-11-01'],
        [$i, 'ticket', 'status', 'new', 'dev_todo', '2026-09-01'],
        [$other, 'issue', 'close_withdrawn', 'closing', 'dev_todo', '2026-09-02'],
        [$other, 'issue', 'status', 'new', 'test_pending', '2026-09-03'],
        [$other, 'issue', 'status', 'test_failed', 'dev_doing', '2026-09-04'],
    ] as [$issue, $subject, $type, $from, $to, $time]) {
        ActivityEvent::create(['project_id' => $pid, 'subject_type' => $subject, 'subject_id' => $issue->id,
            'event_type' => $type, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $this->pm->id, 'created_at' => $time]);
    }
    foreach (['active', 'all', 'schedule', 'close'] as $tab) {
        if ($tab === 'close') {
            foreach ([$i, $other] as $issue) {
                app(IssueCloseService::class)->request($this->pm, $pid, $issue->id, 'closed_pm', '完成', null);
            }
        }
        $in = ['tab' => $tab, 'f' => 1];
        ($this->as)($this->dev)->get(route('dev.index', $in))->assertOk()->assertSee('PM 派工時間')->assertSee('2026-10-05 03:15');
        $sheet = $this->svc->devBook($this->dev, $pid, $in)->sheets[0];
        $values = collect($sheet->rows)->pluck('dispatched_at')->sort()->values()->all();
        expect($values)->toBe(['2026-10-05 03:15', '—']);
        $download = ($this->as)($this->dev)->get(route('dev.export', $in))->assertOk();
        expect(ieText(ieXml($download, 'xl/worksheets/sheet1.xml')))->toContain('PM 派工時間', '2026-10-05 03:15', '—');
    }
})->with(['new', 'discussing']);

it('keeps each closure round, original target, progress and pending owners in the filtered export', function () {
    $pid = $this->p->id;
    $close = app(IssueCloseService::class);
    $issue = ieIssue($this->pm, $this->p, 'pm', ['title' => '交接追蹤', 'status' => 'test_doing',
        'assignee_dev_id' => $this->dev->id, 'assignee_test_id' => $this->tester->id]);
    $close->request($this->pm, $pid, $issue->id, 'closed_pm', '第一次', null);
    $close->finishTask($this->dev, $pid, $issue->closeTasks()->where('role', 'dev')->sole()->id, '已完成');
    $close->withdraw($this->pm, $pid, $issue->id);
    $pm = ($this->as)($this->pm);
    $pm->post(route('meetings.store'), ['type' => 'regular', 'title' => '交接', 'start_at' => '2026-12-01 10:00', 'mode' => 'scheduled'])->assertSessionHasNoErrors();
    $meeting = Meeting::sole();
    $item = $pm->postJson(route('meetings.items.store', $meeting), ['topic' => '結論', 'issue_no' => $issue->issue_no])->assertOk()->json('item.id');
    $pm->putJson(route('meetings.items.update', [$meeting, $item]), ['conclusion' => '同意結案'])->assertOk();
    $pm->postJson(route('meetings.items.close-issue', [$meeting, $item]))->assertOk();
    $tasks = $issue->closeTasks()->where('round', 2)->get()->keyBy('role');
    $close->finishTask($this->dev, $pid, $tasks['dev']->id, '完成第二輪');

    $active = ['tab' => 'close', 'q' => '交接'];
    $page = ($this->as)($this->dev)->get(route('dev.index', $active))->assertOk();
    $row = $page->viewData('rounds')->getCollection()->sole();
    expect($row)->toMatchArray(['round' => 2, 'target' => '會議討論結案', 'progress' => '1／2', 'pending' => 'Test 收尾 '.$this->tester->name]);
    $page->assertSee(route('issues.show', $issue).'#close-tasks')->assertSee($row['requested_at']);
    expect($this->svc->devBook($this->dev, $pid, $active + ['mine' => 1])->sheets[0]->rows)->toBeEmpty();
    $mine = $this->svc->devBook($this->tester, $pid, $active + ['mine' => 1]);
    expect($mine->sheets[0]->rows[0]['progress'])->toBe('1／2')->and($mine->info['只看我待確認'])->toBe('是');
    $close->finishTask($this->tester, $pid, $tasks['test']->id, '完成');

    $all = ['tab' => 'close', 'status' => 'all', 'q' => $issue->issue_no];
    $page = ($this->as)($this->dev)->get(route('dev.index', $all))->assertOk();
    $sheet = $this->svc->devBook($this->dev, $pid, $all)->sheets[0];
    expect($page->viewData('rounds')->pluck('round')->all())->toBe([1, 2]);
    foreach ($page->viewData('rounds') as $index => $row) {
        foreach ($sheet->columns as $key => $heading) {
            expect($key === 'issue_no' ? $sheet->rows[$index][$key]->text : $sheet->rows[$index][$key])->toBe($row[$key]);
        }
    }
    expect($sheet->rows[0])->toMatchArray(['status' => '已撤回', 'target' => 'PM結案', 'progress' => '1／2', 'pending' => '—']);
    expect($sheet->rows[1])->toMatchArray(['status' => '已完成', 'target' => '會議討論結案', 'progress' => '2／2', 'pending' => '—']);
    foreach (['void' => 1, 'done' => 2] as $state => $round) {
        $filtered = $this->svc->devBook($this->dev, $pid, ['status' => $state] + $all)->sheets[0];
        expect(array_column($filtered->rows, 'round'))->toBe([$round]);
    }
    expect($this->svc->devBook($this->dev, $pid, ['q' => '無匹配'] + $all)->sheets[0]->rows)->toBeEmpty();
    $issue->delete();
    expect($this->svc->devBook($this->dev, $pid, $all)->sheets[0]->rows)->toBeEmpty();
});

it('scopes release pool rounds by permission and project and protects both management routes', function ($scope) {
    $pid = $this->p->id;
    $close = app(IssueCloseService::class);
    $pool = ieIssue($this->pm, $this->p, 'pm', ['status' => 'release_pending']);
    $close->request($this->pm, $pid, $pool->id, 'closed_pm', '需要上線確認', null);
    $direct = ieIssue($this->pm, $this->p, 'pm');
    $close->request($this->pm, $pid, $direct->id, 'closed_pm', '直接結案', null);
    $second = Project::create(['code' => 'OTHER', 'name' => 'OTHER', 'ticket_prefix' => 'OTH']);
    $secondPm = ieUser('otherpm', 'internal', ['internal_pm'], $second);
    $foreign = ieIssue($secondPm, $second, 'pm', ['status' => 'release_pending']);
    $close->request($secondPm, $second->id, $foreign->id, 'closed_pm', '另一個專案', null);
    $in = ['tab' => 'close', 'mine' => 1];
    $view = fn ($user, $filters) => ($this->as)($user)->get(route($scope.'.index', $filters))->assertOk()->viewData('rounds');
    expect($view($this->dev, ['tab' => 'close', 'status' => 'all'])->pluck('issue_id')->all())->toBe([$pool->id])
        ->and($view($this->dev, $in)->total())->toBe(0)
        ->and($view($this->tester, $in)->getCollection()->sole()['pending'])->toBe('上線收尾 上線負責人');
    ($this->as)($this->client)->get(route($scope.'.index', $in))->assertForbidden();
    ($this->as)($this->client)->get(route($scope.'.export', $in))->assertForbidden();
})->with(['dev', 'test']);

it('exports all matching wrap-up pages in request order and enforces the export limit', function ($scope) {
    $pid = $this->p->id;
    $issue = ieIssue($this->pm, $this->p, 'pm', ['status' => 'dev_todo', 'assignee_dev_id' => $this->dev->id]);
    $close = app(IssueCloseService::class);
    for ($round = 1; $round <= 21; $round++) {
        $close->request($this->pm, $pid, $issue->id, 'closed_pm', '驗證輪次', null);
        $close->withdraw($this->pm, $pid, $issue->id);
    }
    $in = ['tab' => 'close', 'status' => 'void', 'q' => $issue->issue_no, 'page' => 2];
    $page = ($this->as)($this->dev)->get(route($scope.'.index', $in))->assertOk()->viewData('rounds');
    expect($page->total())->toBe(21)->and($page->pluck('round')->all())->toBe([21])
        ->and($page->previousPageUrl())->toContain('tab=close', 'status=void', 'q='.$issue->issue_no);
    $book = $scope === 'dev' ? $this->svc->devBook($this->dev, $pid, $in) : $this->svc->testBook($this->tester, $pid, $in);
    expect(array_column($book->sheets[0]->rows, 'round'))->toBe(range(1, 21));
    app(SettingRepository::class)->set('export.max_rows', '20');
    ($this->as)($this->dev)->get(route($scope.'.export', $in))->assertRedirect(route($scope.'.index', $in))->assertSessionHas('err');
})->with(['dev', 'test']);

it('shows the latest test handoff in the list, Excel and each historical close round', function ($from) {
    $pid = $this->p->id;
    $svc = app(IssueService::class);
    $close = app(IssueCloseService::class);
    $both = ieUser('both', 'internal', ['internal_pm', 'dev'], $this->p);
    $issue = ieIssue($both, $this->p, 'pm', ['status' => $from, 'assignee_dev_id' => $both->id]);
    $in = ['q' => $issue->issue_no];
    $check = function ($tab, $time, $source) use ($in) {
        $params = $in + ['tab' => $tab];
        ($this->as)($this->tester)->get(route('test.index', $params))->assertOk()
            ->assertSeeInOrder(['測試派工時間', '派工來源', $time, $source]);
        $file = ($this->as)($this->tester)->get(route('test.export', $params))->assertOk();
        expect(ieText(ieXml($file, 'xl/worksheets/sheet1.xml')))->toContain('測試派工時間', '派工來源', $time, $source);
    };
    $this->travelTo(now('UTC')->setDateTime(2026, 10, 4, 19, 15));
    $svc->transition($both, $issue->id, $pid, 'test_pending', ['assignee_test_id' => $this->tester->id]);
    $check('active', '2026-10-05 03:15', 'PM');

    // The first request, withdrawal, re-dispatch and second request share a timestamp.
    $this->travelTo(now()->addMinutes(5));
    $close->request($both, $pid, $issue->id, 'closed_pm', '第一輪', null);
    $close->withdraw($both, $pid, $issue->id);
    $svc->transition($this->tester, $issue->id, $pid, 'test_scheduled', ['test_scheduled_at' => '2026-10-06']);
    $svc->transition($this->tester, $issue->id, $pid, 'test_doing');
    $svc->transition($this->tester, $issue->id, $pid, 'test_failed');
    $check('bounced', '2026-10-05 03:15', 'PM');
    $svc->transition($both, $issue->id, $pid, 'dev_doing');
    $svc->transition($both, $issue->id, $pid, 'test_pending', [], '第二次送測');
    $check('active', '2026-10-05 03:20', '開發');
    $close->request($both, $pid, $issue->id, 'closed_pm', '第二輪', null);
    $check('close', '2026-10-05 03:20', '開發');

    $params = $in + ['tab' => 'close', 'status' => 'all'];
    $page = ($this->as)($this->tester)->get(route('test.index', $params))->assertOk();
    $page->assertSeeInOrder(['測試派工時間', '2026-10-05 03:15', 'PM', '已撤回', '2026-10-05 03:20', '開發']);
    $rows = $page->viewData('rounds')->items();
    expect(array_column($rows, 'dispatch_source', 'round'))->toBe([1 => 'PM', 2 => '開發']);
    $file = ($this->as)($this->tester)->get(route('test.export', $params))->assertOk();
    expect(ieText(ieXml($file, 'xl/worksheets/sheet1.xml')))->toContain("2026-10-05 03:15\nPM", "2026-10-05 03:20\n開發");
})->with(['new', 'discussing']);

it('tracks package handoffs per issue and retains the last handoff across withdrawal', function () {
    $pid = $this->p->id;
    $svc = app(IssueService::class);
    $ids = [];
    foreach (['甲', '乙'] as $title) {
        $ids[] = ieIssue($this->dev, $this->p, 'dev', ['title' => $title, 'status' => 'dev_doing', 'assignee_dev_id' => $this->dev->id])->id;
    }
    $pkg = $svc->createPackage($this->dev, $pid, '批次送測');
    $this->travelTo(now('UTC')->setDateTime(2026, 11, 2, 4, 30));
    $svc->addToPackage($this->dev, $pid, $pkg, $ids, $this->tester->id);
    $this->travelTo(now()->addDay());
    $svc->removeFromPackage($this->dev, $pid, $pkg, $ids[0]);
    $close = app(IssueCloseService::class);
    $close->request($this->pm, $pid, $ids[0], 'closed_pm', '撤回後送結案', null);
    $row = $this->svc->testBook($this->tester, $pid, ['tab' => 'close'])->sheets[0]->rows[0];
    expect($row)->toMatchArray(['dispatched_at' => '2026-11-02 12:30', 'dispatch_source' => '開發']);
    $close->withdraw($this->pm, $pid, $ids[0]);
    $svc->addToPackage($this->dev, $pid, $pkg, [$ids[0]], null);
    $sheet = $this->svc->testBook($this->tester, $pid, ['mine' => 1])->sheets[0];
    $dates = array_column($sheet->rows, 'dispatched_at', 'title');
    expect($dates['甲'])->toBe('2026-11-03 12:30')->and($dates['乙'])->toBe('2026-11-02 12:30');
});

it('keeps a pre-handoff round blank after a later same-second dispatch', function () {
    $this->freezeTime();
    $pid = $this->p->id;
    $svc = app(IssueService::class);
    $close = app(IssueCloseService::class);
    $issue = ieIssue($this->dev, $this->p, 'dev', ['status' => 'dev_doing', 'assignee_dev_id' => $this->dev->id]);
    $close->request($this->pm, $pid, $issue->id, 'closed_pm', '尚未送測', null);
    $close->withdraw($this->pm, $pid, $issue->id);
    $svc->transition($this->dev, $issue->id, $pid, 'test_pending', ['assignee_test_id' => $this->tester->id], '首次送測');
    $close->request($this->pm, $pid, $issue->id, 'closed_pm', '送測後結案', null);
    $tasks = $issue->closeTasks()->where('round', 2)->get()->keyBy('role');
    $close->finishTask($this->dev, $pid, $tasks['dev']->id, '開發已確認');
    $mine = ['tab' => 'close', 'mine' => 1];
    $page = ($this->as)($this->tester)->get(route('test.index', $mine))->assertOk();
    expect($page->viewData('rounds')->getCollection()->sole())->toMatchArray(['round' => 2, 'progress' => '1／2', 'dispatch_source' => '開發']);
    $close->finishTask($this->tester, $pid, $tasks['test']->id, '測試已確認');
    $history = $this->svc->testBook($this->tester, $pid, ['tab' => 'close', 'status' => 'all'])->sheets[0]->rows;
    expect($history[0])->toMatchArray(['round' => 1, 'status' => '已撤回', 'dispatched_at' => '—', 'dispatch_source' => '—']);
    expect($history[1])->toMatchArray(['round' => 2, 'status' => '已完成', 'progress' => '2／2', 'dispatched_at' => tw(now()), 'dispatch_source' => '開發']);
    expect($this->svc->testBook($this->tester, $pid, $mine)->sheets[0]->rows)->toBeEmpty();
});

it('leaves test dispatch blank for new findings and legacy issues without matching project events', function () {
    $found = ieIssue($this->tester, $this->p, 'test', ['assignee_dev_id' => $this->dev->id]);
    $legacy = ieIssue($this->pm, $this->p, 'pm', ['status' => 'test_pending', 'assignee_test_id' => $this->tester->id]);
    $other = Project::create(['code' => 'ELSE', 'name' => 'ELSE', 'ticket_prefix' => 'ELSE']);
    foreach ([[$other->id, 'issue', 'status'], [$this->p->id, 'ticket', 'status'], [$this->p->id, 'issue', 'close_withdrawn']] as [$project, $subject, $type]) {
        ActivityEvent::create(['project_id' => $project, 'subject_type' => $subject, 'subject_id' => $legacy->id, 'event_type' => $type,
            'from_status' => 'new', 'to_status' => 'test_pending', 'actor_id' => $this->pm->id]);
    }
    foreach (['active' => $legacy, 'bounced' => $found] as $tab => $issue) {
        $params = ['tab' => $tab, 'q' => $issue->issue_no];
        $page = ($this->as)($this->tester)->get(route('test.index', $params))->assertOk();
        expect($page->viewData('testDispatches'))->toBeEmpty();
        $row = $this->svc->testBook($this->tester, $this->p->id, $params)->sheets[0]->rows[0];
        expect($row)->toMatchArray(['issue_no' => $issue->issue_no, 'dispatched_at' => '—', 'dispatch_source' => '—']);
    }
});

it('computes overdue days in Taipei time for non-terminal issues only', function () {
    $due = now('Asia/Taipei')->subDays(3)->toDateString();
    ieIssue($this->dev, $this->p, 'dev', ['title' => '逾期', 'assignee_dev_id' => $this->dev->id, 'current_due' => $due]);
    ieIssue($this->dev, $this->p, 'dev', ['title' => '結案', 'assignee_dev_id' => $this->dev->id, 'current_due' => $due, 'status' => 'released']);
    ieIssue($this->dev, $this->p, 'dev', ['title' => '未到', 'assignee_dev_id' => $this->dev->id, 'current_due' => now('Asia/Taipei')->addDays(2)->toDateString()]);

    $sheet = $this->svc->issueSheet('dev_issues', $this->p->id, ['sort' => 'no', 'dir' => 'asc']);
    $by = array_column($sheet->rows, 'overdue_days', 'title');
    expect($by['逾期'])->toBe(3)->and($by['結案'])->toBeNull()->and($by['未到'])->toBeNull();
});

it('writes content, addendum, comment, linked ticket and attachment into the detail sheet', function () {
    $i = ieIssue($this->dev, $this->p, 'dev', ['title' => '詳細單', 'content_html' => '<p>主要內容文字</p>', 'assignee_dev_id' => $this->dev->id]);
    app(IssueService::class)->addComment($this->dev, $i->id, $this->p->id, '<p>內部留言文字</p>');
    app(IssueService::class)->addAddendum($this->pm, $i->id, $this->p->id, '<p>補充文字</p>');
    $t = ClientTicket::create(['project_id' => $this->p->id, 'ticket_no' => 'MES-0001', 'ticket_type' => 'bug', 'title' => '客戶標題', 'status' => 'in_progress', 'content_html' => '<p>c</p>', 'created_by' => $this->client->id, 'current_rev' => 1]);
    $i->tickets()->attach($t->id, ['based_on_rev' => 1, 'linked_by' => $this->pm->id, 'linked_at' => now()]);
    Attachment::create(['owner_type' => 'issue', 'owner_id' => $i->id, 'disk_path' => 'x/a', 'original_name' => 'spec.pdf', 'mime' => 'x/y', 'size' => 1, 'sha256' => str_repeat('a', 64), 'state' => 'bound', 'uploaded_by' => $this->dev->id]);

    $res = ($this->as)($this->dev)->get(route('dev.export', ['tab' => 'all', 'f' => 1]))->assertOk();
    expect($res->headers->get('content-type'))->toContain('spreadsheetml');
    $detail = ieText(ieXml($res, 'xl/worksheets/sheet2.xml'));
    expect($detail)->toContain('詳細單')->toContain('主要內容文字')->toContain('補充文字')->toContain('內部留言文字')
        ->toContain('補充說明')->toContain('內部留言')->toContain('MES-0001 客戶標題（')->toContain('spec.pdf')->toContain('iedev')->toContain('iepm');
    expect(preg_match('~\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}\] iedev~', $detail))->toBe(1);
    expect(ieXml($res, 'xl/worksheets/sheet2.xml').ieXml($res, 'xl/worksheets/_rels/sheet2.xml.rels'))->toContain('/attachments/');
});

it('hides a column whose lookup row is inactive', function () {
    LookupItem::where('category', 'export_test_issues')->where('code', 'severity')->update(['is_active' => 0]);
    app(WorkflowService::class);
    $sheet = app(IssueExportService::class)->issueSheet('test_issues', $this->p->id, []);
    expect($sheet->columns)->not->toHaveKey('severity')->and($sheet->columns)->toHaveKey('title');
});

it('redirects with an error when over the row limit', function () {
    ieIssue($this->dev, $this->p, 'dev', ['title' => 'a', 'assignee_dev_id' => $this->dev->id]);
    ieIssue($this->dev, $this->p, 'dev', ['title' => 'b', 'assignee_dev_id' => $this->dev->id]);
    app(SettingRepository::class)->set('export.max_rows', '1');
    ($this->as)($this->dev)->get(route('dev.export', ['tab' => 'all', 'f' => 1]))
        ->assertRedirect(route('dev.index', ['tab' => 'all', 'f' => 1]))->assertSessionHas('err');
    ($this->as)($this->tester)->get(route('test.export', ['tab' => 'active']))->assertOk();
});

it('forbids export without data.export and for client users', function () {
    $d = ($this->as)($this->dev);
    $d->get(route('dev.export'))->assertOk();
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->p->id);
    Role::findByName('dev')->revokePermissionTo('data.export');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ($this->as)($this->dev->fresh())->get(route('dev.export'))->assertForbidden();
    ($this->as)($this->client)->get(route('dev.export'))->assertForbidden();
    ($this->as)($this->client)->get(route('test.export'))->assertForbidden();
    ($this->as)($this->dev->fresh())->get(route('dev.index'))->assertOk()->assertDontSee('匯出 Excel');
});

it('shows the export button to permitted users', function () {
    ($this->as)($this->dev)->get(route('dev.index'))->assertOk()->assertSee('匯出 Excel');
    ($this->as)($this->tester)->get(route('test.index'))->assertOk()->assertSee('匯出 Excel');
});

it('exports the PM page as one workbook with ticket and issue sheets', function () {
    $client = ieUser('iepmc', 'client', ['client_pm'], $this->p);
    $ts = app(TicketService::class);
    $t = $ts->create($client, $this->p->id, ['ticket_type' => 'requirement', 'title' => 'PM匯出客戶單', 'content_html' => '<p>客戶內容</p>']);
    $ts->accept($this->pm, $t->id, $this->p->id);
    ieIssue($this->pm, $this->p, 'pm', ['title' => 'PM匯出內部單']);
    $s = fn () => $this->actingAs($this->pm)->withSession(['project_id' => $this->p->id]);

    $s()->get('/pm')->assertOk()->assertSee(route('pm.export'), false);
    $r = $s()->get('/pm/export?oa=0');
    $r->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $wb = ieXml($r, 'xl/workbook.xml');
    expect($wb)->toContain('name="客戶單"')->toContain('name="客戶單詳細"')->toContain('name="內部單"')->toContain('name="內部單詳細"')->toContain('name="匯出資訊"');
    $all = '';
    foreach (range(1, 5) as $n) {
        $all .= ieText(ieXml($r, "xl/worksheets/sheet{$n}.xml"));
    }
    expect($all)->toContain('PM匯出客戶單')->toContain('PM匯出內部單')->toContain('客戶內容');

    ActivityEvent::where('subject_type', 'export')->exists() || throw new RuntimeException('no audit');
    ieIssue($this->pm, $this->p, 'pm', ['title' => '第二筆']);
    app(SettingRepository::class)->set('export.max_rows', '1');
    $s()->get('/pm/export?oa=0')->assertRedirect()->assertSessionHas('err');
});
