<?php

use App\Models\Attachment;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\LookupItem;
use App\Models\Project;
use App\Models\TicketComment;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Repositories\TicketRepository;
use App\Services\Export\TicketExportService;
use App\Services\WorkflowService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function teUser(string $account, array $roles, Project $p, string $type = 'client'): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@t.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

function teTicket(Project $p, User $by, array $o = []): ClientTicket
{
    static $n = 0;
    $n++;

    return ClientTicket::create($o + [
        'project_id' => $p->id, 'ticket_no' => 'TE-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'ticket_type' => 'anomaly', 'anomaly_category' => 'ui', 'title' => "票{$n}", 'content_html' => '<p>內容'.$n.'</p>',
        'status' => 'submitted', 'created_by' => $by->id,
    ]);
}

function teXml(mixed $r, string $entry): string
{
    $path = is_string($r) ? $r : $r->baseResponse->getFile()->getPathname();
    $z = new ZipArchive;
    $z->open($path);
    $s = (string) $z->getFromName($entry);
    $z->close();

    return $s;
}

function teTextOf(string $xml): string
{
    return html_entity_decode(strip_tags(str_replace('</c>', ' ', $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

beforeEach(function () {
    $this->p = Project::create(['code' => 'MES', 'name' => 'MES', 'ticket_prefix' => 'MES']);
    $this->pm = teUser('tpm', ['client_pm'], $this->p);
    $this->view = teUser('tview', ['client_view'], $this->p);
    $this->ipm = teUser('ipm', ['internal_pm'], $this->p, 'internal');
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
});

it('exports exactly the ids shown by the index page for several filter combos', function () {
    teTicket($this->p, $this->pm, ['title' => '甲 按鈕', 'ticket_type' => 'anomaly']);
    teTicket($this->p, $this->pm, ['title' => '乙 需求', 'ticket_type' => 'requirement', 'anomaly_category' => null]);
    teTicket($this->p, $this->pm, ['title' => '丙 按鈕', 'ticket_type' => 'requirement', 'anomaly_category' => null, 'status' => 'closed']);
    teTicket($this->p, $this->view, ['title' => '丁', 'status' => 'pending_close']);
    $svc = app(TicketExportService::class);
    $wf = app(WorkflowService::class);

    foreach ([
        ['tab' => 'open'], ['tab' => 'closed'], ['tab' => 'pending'], ['tab' => 'open', 'type' => 'requirement'],
        ['tab' => 'open', 'q' => '按鈕'], ['tab' => 'open', 'sort' => 'no', 'dir' => 'asc'], ['tab' => 'open', 'mine' => $this->pm->id],
    ] as $f) {
        $expected = app(TicketRepository::class)->paginate($this->p->id, $wf->codesForTab($f['tab']), $f)->pluck('ticket_no')->all();
        $sheet = $svc->clientBook($this->pm, $this->p->id, $f)->sheets[0];
        expect(array_column($sheet->rows, 'ticket_no'))->toBe($expected);
        expect($sheet->details)->toHaveCount(count($expected));
    }
});

it('follows lookup column order and hides inactive columns', function () {
    teTicket($this->p, $this->pm);
    LookupItem::where('category', 'export_client_tickets')->where('code', 'creator')->update(['is_active' => 0]);
    LookupItem::where('category', 'export_client_tickets')->where('code', 'ticket_no')->update(['sort' => 999]);
    $cols = array_keys(app(TicketExportService::class)->clientBook($this->pm, $this->p->id, ['tab' => 'open'])->sheets[0]->columns);
    expect($cols)->not->toContain('creator')->and(end($cols))->toBe('ticket_no');
});

it('downloads an xlsx with detail content, comment header and attachment link but no internal data', function () {
    $t = teTicket($this->p, $this->pm, ['title' => '含留言', 'content_html' => '<p>主要內容文字</p>']);
    $c = TicketComment::create(['ticket_id' => $t->id, 'kind' => 'supplement', 'content_html' => '<p>補充文字</p>', 'created_by' => $this->pm->id, 'created_at' => now()]);
    foreach ([['ticket', $t->id, 'a.png'], ['ticket_comment', $c->id, 'b.pdf']] as [$ot, $oid, $name]) {
        Attachment::create(['owner_type' => $ot, 'owner_id' => $oid, 'disk_path' => 'x/'.$name, 'original_name' => $name, 'mime' => 'x/y', 'size' => 1, 'sha256' => str_repeat('a', 64), 'state' => 'bound', 'uploaded_by' => $this->pm->id]);
    }
    $issue = Issue::create(['project_id' => $this->p->id, 'issue_no' => 'ISS-SECRET-1', 'origin' => 'client', 'title' => '內部機密單', 'content_html' => '<p>x</p>', 'stage' => 'PM', 'status' => 'new', 'created_by' => ($this->ipm)->id]);
    $t->issues()->attach($issue->id, ['based_on_rev' => 1, 'linked_by' => $this->ipm->id, 'linked_at' => now()]);

    $res = ($this->as)($this->pm)->get(route('tickets.export', ['tab' => 'open']));
    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('spreadsheetml');
    expect($res->headers->get('content-disposition'))->toContain('MES');
    $all = teTextOf(teXml($res, 'xl/worksheets/sheet1.xml').teXml($res, 'xl/worksheets/sheet2.xml'));
    expect($all)->toContain('含留言')->toContain('主要內容文字')->toContain('補充文字')->toContain('tpm（補充說明）')
        ->toContain('a.png')->toContain('b.pdf')
        ->not->toContain('ISS-SECRET-1')->not->toContain('內部機密單');
    $raw = teXml($res, 'xl/worksheets/sheet2.xml');
    expect($raw.teXml($res, 'xl/worksheets/_rels/sheet2.xml.rels'))->toContain('/attachments/');
});

it('builds the PM sheet with linked issues and flags', function () {
    $t = teTicket($this->p, $this->pm, ['accepted_by' => $this->ipm->id, 'accepted_at' => now(), 'needs_pm_action' => true, 'dispatched_at' => now()]);
    $issue = Issue::create(['project_id' => $this->p->id, 'issue_no' => 'ISS-77', 'origin' => 'client', 'title' => '關聯', 'content_html' => '<p>x</p>', 'stage' => 'PM', 'status' => 'new', 'created_by' => $this->ipm->id]);
    $t->issues()->attach($issue->id, ['based_on_rev' => 1, 'linked_by' => $this->ipm->id, 'linked_at' => now()]);
    teTicket($this->p, $this->pm, ['accepted_at' => now(), 'title' => '不需處置']);

    $sheet = app(TicketExportService::class)->pmTicketsSheet($this->ipm, $this->p->id, ['oa' => '1']);
    expect($sheet->rows)->toHaveCount(1);
    $row = $sheet->rows[0];
    expect($row['linked_issues'])->toBe('ISS-77')->and($row['dispatched'])->toBeTrue()->and($row['needs_pm_action'])->toBeTrue()
        ->and($row['accepted_by'])->toBe('ipm')->and($row['unacked_revisions'])->toBe(0);
    expect($sheet->details[0]->sections['關聯內部單'][0]->text)->toContain('ISS-77 關聯（');
    expect(app(TicketExportService::class)->pmTicketsSheet($this->ipm, $this->p->id, ['oa' => '0'])->rows)->toHaveCount(2);
});

it('redirects with an error when over the row limit', function () {
    teTicket($this->p, $this->pm);
    teTicket($this->p, $this->pm);
    app(SettingRepository::class)->set('export.max_rows', '1');
    ($this->as)($this->pm)->get(route('tickets.export', ['tab' => 'open', 'q' => '票']))
        ->assertRedirect(route('tickets.index', ['tab' => 'open', 'q' => '票']))->assertSessionHas('err');
});

it('forbids users without data.export and hides the button', function () {
    teTicket($this->p, $this->pm);
    ($this->as)($this->pm)->get(route('tickets.index'))->assertSee('匯出 Excel');
    Role::findByName('client_view')->revokePermissionTo('data.export');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ($this->as)($this->view)->get(route('tickets.export'))->assertForbidden();
    ($this->as)($this->view)->get(route('tickets.index'))->assertOk()->assertDontSee('匯出 Excel');
});
