<?php

use App\Models\Attachment;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\LookupItem;
use App\Models\Project;
use App\Models\User;
use App\Repositories\IssueRepository;
use App\Repositories\SettingRepository;
use App\Services\Dev\DevService;
use App\Services\Export\IssueExportService;
use App\Services\IssueService;
use App\Services\Pm\PmQueryService;
use App\Services\Test\TestService;
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
    $ts = app(\App\Services\TicketService::class);
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

    \App\Models\ActivityEvent::where('subject_type', 'export')->exists() || throw new RuntimeException('no audit');
    ieIssue($this->pm, $this->p, 'pm', ['title' => '第二筆']);
    app(\App\Repositories\SettingRepository::class)->set('export.max_rows', '1');
    $s()->get('/pm/export?oa=0')->assertRedirect()->assertSessionHas('err');
});
