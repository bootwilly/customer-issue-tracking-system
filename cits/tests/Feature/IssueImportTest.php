<?php

use App\Models\ActivityEvent;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\IssueAddendum;
use App\Models\IssueImportRow;
use App\Models\MesPage;
use App\Models\Project;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Services\Import\IssueImportService;
use App\Services\Import\XlsxIssueReader;
use App\Services\IssueService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Hyperlink;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\PermissionRegistrar;

// Excel import of PM internal issues (standard template)

const IMP_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function impUser(string $account, string $type, array $roles, Project $p): User
{
    $u = User::create(['account' => $account, 'name' => $account, 'email' => "$account@imp.test", 'password' => 'secret1', 'user_type' => $type, 'status' => 'active']);
    $p->members()->attach($u->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
    $u->assignRole($roles);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $u->fresh();
}

/**
 * Standard workbook: 問題清單 + one detail sheet per row that has 'detail' + hidden lists sheet with the version.
 * With $drawing, the first detail sheet gets a 100x100 px picture with a red box on it and a loose text box.
 *
 * @param  list<array<string, string>>  $rows  field code => value
 */
function impWorkbook(array $rows, bool $drawing = false, ?string $version = IssueImportService::TEMPLATE_VERSION, array $extraHeaders = []): string
{
    $cols = ['no' => '序號', 'date' => '日期', 'process_type' => '處理類型', 'func' => '功能名稱', 'mes_page' => 'MES頁面', 'title' => '標題',
        'content' => '問題內容', 'detail' => '明細表', 'status' => '狀態', 'severity' => '嚴重度', 'pm' => 'PM'] + $extraHeaders;
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $w = new Writer;
    $w->openToFile($path);
    $w->getCurrentSheet()->setName('問題清單');
    $w->addRow(new Row(array_map(fn ($h) => new StringCell($h, null), array_values($cols))));
    $details = [];
    foreach ($rows as $r) {
        $cells = [];
        foreach (array_keys($cols) as $k) {
            $v = (string) ($r[$k] ?? '');
            if ($k === 'detail' && $v !== '') {
                $details[$v] = $r;
                $cells[] = new StringCell($v, null, hyperlinkUrl: Hyperlink::toCell('A1', $v));
            } else {
                $cells[] = new StringCell($v, null);
            }
        }
        $w->addRow(new Row($cells));
    }
    foreach ($details as $name => $r) {
        $w->addNewSheetAndMakeItCurrent()->setName($name);
        $w->addRow(new Row([new StringCell($r['content'], null)]));   // repeats 問題內容: must be dropped
        $w->addRow(new Row([new StringCell('', null)]));
        $w->addRow(new Row([new StringCell($r['detail_text'] ?? '結論:補充文字', null)]));
        $w->addRow(new Row([new StringCell('欄位A', null), new StringCell('欄位B', null)]));
    }
    $w->addNewSheetAndMakeItCurrent()->setName('清單');
    $w->addRow(new Row([new StringCell('處理類型', null), new StringCell('', null), new StringCell($version === null ? '' : IssueImportService::VERSION_LABEL, null)]));
    $w->addRow(new Row([new StringCell('UI修改', null), new StringCell('', null), new StringCell((string) $version, null)]));
    $w->close();

    if ($drawing) {
        $z = new ZipArchive;
        $z->open($path);
        $sheet = 'xl/worksheets/sheet2.xml';
        $z->addFromString($sheet, str_replace('</worksheet>', '<drawing xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rIdDr1"/></worksheet>', $z->getFromName($sheet)));
        $rels = $z->getFromName('xl/worksheets/_rels/sheet2.xml.rels') ?: '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        $z->addFromString('xl/worksheets/_rels/sheet2.xml.rels', str_replace('</Relationships>', '<Relationship Id="rIdDr1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>', $rels));
        $ns = 'xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        // the cell anchors are what counts (a:xfrm below is deliberately stale, as Excel often leaves it)
        $anchor = fn (int $c1, int $o1, int $r1, int $c2, int $o2, int $r2) => "<xdr:from><xdr:col>$c1</xdr:col><xdr:colOff>$o1</xdr:colOff><xdr:row>$r1</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:to><xdr:col>$c2</xdr:col><xdr:colOff>$o2</xdr:colOff><xdr:row>$r2</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>";
        $z->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0" encoding="UTF-8"?><xdr:wsDr '.$ns.'>'
            .'<xdr:twoCellAnchor>'.$anchor(0, 0, 4, 2, 0, 9).'<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="p"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/><a:stretch/></xdr:blipFill>'
            .'<xdr:spPr><a:xfrm><a:off x="0" y="20000000"/><a:ext cx="952500" cy="952500"/></a:xfrm><a:prstGeom prst="rect"/></xdr:spPr></xdr:pic><xdr:clientData/></xdr:twoCellAnchor>'
            .'<xdr:twoCellAnchor>'.$anchor(0, 190500, 5, 0, 571500, 6).'<xdr:sp><xdr:nvSpPr><xdr:cNvPr id="3" name="box"/><xdr:cNvSpPr/></xdr:nvSpPr>'
            .'<xdr:spPr><a:xfrm><a:off x="190500" y="20190500"/><a:ext cx="381000" cy="190500"/></a:xfrm><a:prstGeom prst="rect"/><a:noFill/><a:ln w="19050"><a:solidFill><a:srgbClr val="FF0000"/></a:solidFill></a:ln></xdr:spPr></xdr:sp><xdr:clientData/></xdr:twoCellAnchor>'
            .'<xdr:twoCellAnchor>'.$anchor(8, 0, 20, 9, 0, 21).'<xdr:sp><xdr:nvSpPr><xdr:cNvPr id="4" name="note"/><xdr:cNvSpPr txBox="1"/></xdr:nvSpPr>'
            .'<xdr:spPr><a:xfrm><a:off x="9000000" y="30000000"/><a:ext cx="952500" cy="285750"/></a:xfrm><a:prstGeom prst="rect"/><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></xdr:spPr>'
            .'<xdr:txBody><a:bodyPr/><a:p><a:r><a:rPr sz="1200"/><a:t>說明框</a:t></a:r></a:p></xdr:txBody></xdr:sp><xdr:clientData/></xdr:twoCellAnchor>'
            .'</xdr:wsDr>');
        $z->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/></Relationships>');
        $z->addFromString('xl/media/image1.png', base64_decode(IMP_PNG));
        $z->addFromString('[Content_Types].xml', str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/></Types>', $z->getFromName('[Content_Types].xml')));
        $z->close();
    }

    return $path;
}

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->p = Project::create(['code' => 'IM', 'name' => 'IM', 'ticket_prefix' => 'IM']);
    $this->pm = impUser('impm', 'internal', ['internal_pm'], $this->p);
    $this->page = MesPage::create(['project_id' => $this->p->id, 'node_type' => 'page', 'name' => '製令單設定', 'page_path' => '~/a.aspx', 'sort' => 1, 'is_active' => true]);
    $this->as = fn (User $u) => $this->actingAs($u)->withSession(['project_id' => $this->p->id]);
    $this->upload = function (User $u, string $path): int {
        $bytes = file_get_contents($path);
        $init = ($this->as)($u)->postJson('/uploads/init', ['name' => 'book.xlsx', 'size' => strlen($bytes), 'context' => IssueImportService::FILE_CONTEXT])->assertOk()->json();
        ($this->as)($u)->post("/uploads/{$init['id']}/chunk", ['index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('0.part', $bytes)])->assertOk();

        return ($this->as)($u)->postJson("/uploads/{$init['id']}/complete")->assertOk()->json('attachment_id');
    };
    $this->picture = function (User $u, int $fileId, string $no): int {
        return ($this->as)($u)->post('/uploads/image', ['file' => UploadedFile::fake()->createWithContent('c.png', base64_decode(IMP_PNG)),
            'context' => "issue:import:$fileId:$no"])->assertOk()->json('attachment_id');
    };
    $this->rows = [
        ['no' => '1', 'date' => '2026-05-13', 'process_type' => 'UI修改', 'func' => '製令單設定', 'mes_page' => '製令單設定', 'title' => '製令單設定：加查詢',
            'content' => '問題1:建單查詢是否可以增加PoNumber查詢', 'detail' => '問題1', 'status' => '待討論', 'severity' => '3', 'pm' => '20260724 待確認'],
        ['no' => '2', 'func' => 'BOM', 'title' => 'BOM：已結案', 'content' => 'x', 'status' => '結案'],
        ['no' => '3', 'func' => 'BOM', 'title' => '', 'content' => 'y', 'status' => '待討論'],
        ['no' => '4', 'func' => '排程', 'process_type' => '不存在', 'title' => '排程：自動', 'content' => '參考 8_共享資料\報表', 'status' => '規格確認'],
    ];
});

it('downloads the standard template with lists, validations and the version marker', function () {
    $res = ($this->as)($this->pm)->get('/pm/issues/import/template')->assertOk();
    $path = $res->baseResponse->getFile()->getPathname();
    $r = new XlsxIssueReader($path, 50 * 1048576);
    expect($r->sheetNames())->toBe(['問題清單', '說明', '清單'])
        ->and($r->valueBelow('清單', IssueImportService::VERSION_LABEL))->toBe(IssueImportService::TEMPLATE_VERSION)
        ->and(array_values($r->sheetCells('問題清單')['rows'][1]))->toContain('序號', '標題', '問題內容', '狀態', '備註')
        ->and(array_values(array_column($r->sheetCells('清單')['rows'], 'D')))->toContain('製令單設定');
    $z = new ZipArchive;
    $z->open($path);
    $sheet1 = $z->getFromName('xl/worksheets/sheet1.xml');
    expect($sheet1)->toContain('<dataValidation')->toContain('清單!$B$2')->toContain('COUNTIF')
        ->and($z->getFromName('xl/workbook.xml'))->toContain('state="hidden"');
});

it('previews rows: new / skip / error, warnings, detail blocks with the red box on the picture', function () {
    $file = ($this->upload)($this->pm, impWorkbook($this->rows, true));
    $res = ($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $file, 'source' => 'YC-MES'])->assertOk()->json();
    $rows = collect($res['rows'])->keyBy('no');

    expect($res['counts'])->toMatchArray(['new' => 2, 'skip' => 1, 'error' => 1])
        ->and($rows['1']['state'])->toBe('new')
        ->and($rows['1']['process_type'])->toBe('ui_change')
        ->and($rows['1']['mes_page_id'])->toBe($this->page->id)
        ->and($rows['1']['notes'])->toBe([['label' => 'PM', 'value' => '20260724 待確認']])
        ->and($rows['2']['state'])->toBe('skip')
        ->and($rows['3']['state'])->toBe('error')
        ->and($rows['4']['process_type'])->toBeNull()
        ->and(implode(' ', $rows['4']['warnings']))->toContain('不在選項中')->toContain('外部檔案路徑');

    $types = array_column($rows['1']['blocks'], 'type');
    expect($types)->toBe(['text', 'table', 'image', 'drawing']);   // repeated 問題內容 dropped; order by position
    $image = $rows['1']['blocks'][2];
    expect($image['media'])->toBe('xl/media/image1.png')
        ->and($image['overlays'])->toHaveCount(1)
        ->and($image['overlays'][0]['x'])->toBe(190500)
        ->and($image['overlays'][0]['y'])->toBeGreaterThan(0)
        ->and($image['overlays'][0]['w'])->toBe(381000)
        ->and($image['overlays'][0]['stroke']['color'])->toBe('#FF0000')
        ->and($rows['1']['blocks'][3]['overlays'][0]['text']['paras'][0]['text'])->toBe('說明框')
        ->and($rows['1']['blocks'][1]['rows'])->toBe([['欄位A', '欄位B']]);

    ($this->as)($this->pm)->get("/pm/issues/import/{$file}/media?p=xl/media/image1.png")->assertOk()->assertHeader('Content-Type', 'image/png');
    ($this->as)($this->pm)->get("/pm/issues/import/{$file}/media?p=xl/workbook.xml")->assertNotFound();
});

it('creates issues in one go, binds the pictures and never imports the same line twice', function () {
    $file = ($this->upload)($this->pm, impWorkbook($this->rows, true));
    ($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $file, 'source' => 'YC-MES'])->assertOk();
    $img = ($this->picture)($this->pm, $file, '1');
    $draw = ($this->picture)($this->pm, $file, '1');

    $res = ($this->as)($this->pm)->postJson('/pm/issues/import/apply', ['provenance' => 'meeting', 'file_id' => $file, 'source' => 'YC-MES', 'rows' => [
        ['no' => '1', 'action' => 'create', 'images' => [$img, $draw], 'process_type' => 'feature', 'mes_page_id' => $this->page->id],
        ['no' => '4', 'action' => 'create', 'images' => []],
    ]])->assertOk()->json('done');
    expect($res)->toHaveCount(2);

    $i = Issue::find($res[0]['issue_id']);
    expect($i->status)->toBe('new')->and($i->origin)->toBe('pm')->and($i->severity)->toBe(3)
        ->and($i->process_type)->toBe('feature')->and($i->mes_page_id)->toBe($this->page->id)
        ->and($i->title)->toBe('製令單設定：加查詢')
        ->and($i->content_html)->toContain('來源：YC-MES #1')->toContain("/attachments/$img")->toContain("/attachments/$draw")
        ->toContain('<td>欄位A</td>')->toContain('<strong>PM</strong>')->not->toContain('<script');
    expect(Attachment::find($img)->state)->toBe('bound')->and(Attachment::find($img)->owner_id)->toBe($i->id)
        ->and(IssueImportRow::where('source', 'YC-MES')->count())->toBe(2);

    $again = ($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $file, 'source' => 'YC-MES'])->json('rows');
    expect(collect($again)->keyBy('no')['1']['state'])->toBe('same')
        ->and(collect($again)->keyBy('no')['1']['existing']['issue_no'])->toBe($i->issue_no);
    ($this->as)($this->pm)->postJson('/pm/issues/import/apply', ['provenance' => 'meeting', 'file_id' => $file, 'source' => 'YC-MES', 'rows' => [['no' => '4', 'action' => 'create', 'images' => []]]])
        ->assertStatus(422);
    expect(Issue::count())->toBe(2);

    // another source code is another record: the same line can be imported again there
    expect(collect(($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $file, 'source' => 'OTHER'])->json('rows'))->keyBy('no')['4']['state'])->toBe('new');
});

it('refuses pictures of another row, a wrong picture count and skipped or broken rows', function () {
    $file = ($this->upload)($this->pm, impWorkbook($this->rows, true));
    $foreign = ($this->picture)($this->pm, $file, '4');
    $own = ($this->picture)($this->pm, $file, '1');
    $apply = fn (array $rows) => ($this->as)($this->pm)->postJson('/pm/issues/import/apply', ['provenance' => 'meeting', 'file_id' => $file, 'source' => 'S', 'rows' => $rows]);

    $apply([['no' => '1', 'action' => 'create', 'images' => [$own, $foreign]]])->assertStatus(422);
    $apply([['no' => '1', 'action' => 'create', 'images' => [$own]]])->assertStatus(422)->assertJsonPath('message', '序號 1 的圖片數量不符，請重新預覽。');
    $apply([['no' => '2', 'action' => 'create', 'images' => []]])->assertStatus(422);
    $apply([['no' => '3', 'action' => 'create', 'images' => []]])->assertStatus(422);
    // a valid row in the same request does not slip through either: all or nothing
    $apply([['no' => '4', 'action' => 'create', 'images' => []], ['no' => '2', 'action' => 'create', 'images' => []]])->assertStatus(422);
    expect(Issue::count())->toBe(0);
});

it('turns a changed line of an imported row into an addendum', function () {
    $file = ($this->upload)($this->pm, impWorkbook([$this->rows[3]]));
    ($this->as)($this->pm)->postJson('/pm/issues/import/apply', ['provenance' => 'meeting', 'file_id' => $file, 'source' => 'S', 'rows' => [['no' => '4', 'action' => 'create', 'images' => []]]])->assertOk();

    $edited = $this->rows[3];
    $edited['content'] .= "\n20260801 補充：改為每日排程";
    $file2 = ($this->upload)($this->pm, impWorkbook([$edited]));
    $row = ($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $file2, 'source' => 'S'])->json('rows.0');
    expect($row['state'])->toBe('changed');

    ($this->as)($this->pm)->postJson('/pm/issues/import/apply', ['provenance' => 'internal', 'file_id' => $file2, 'source' => 'S', 'rows' => [['no' => '4', 'action' => 'create', 'images' => []]]])->assertStatus(422);
    ($this->as)($this->pm)->postJson('/pm/issues/import/apply', ['provenance' => 'internal', 'file_id' => $file2, 'source' => 'S', 'rows' => [['no' => '4', 'action' => 'addendum', 'images' => []]]])->assertOk();
    expect(Issue::count())->toBe(1)
        ->and(IssueAddendum::first()->content_html)->toContain('改為每日排程')
        ->and(($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $file2, 'source' => 'S'])->json('rows.0.state'))->toBe('same');
    ($this->as)($this->pm)->get(route('pm.index', ['provenance' => 'meeting']))->assertOk()->assertSee('Excel S #4');
});

it('blocks a line whose issue is in the recycle bin and imports it again after a permanent delete', function () {
    $file = ($this->upload)($this->pm, impWorkbook([$this->rows[3]]));
    $id = ($this->as)($this->pm)->postJson('/pm/issues/import/apply', ['provenance' => 'meeting', 'file_id' => $file, 'source' => 'S', 'rows' => [['no' => '4', 'action' => 'create', 'images' => []]]])
        ->assertOk()->json('done.0.issue_id');
    ($this->as)($this->pm)->delete(route('issues.destroy', $id))->assertSessionHas('ok');

    $row = ($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $file, 'source' => 'S'])->json('rows.0');
    expect($row['state'])->toBe('error')->and($row['errors'])->toContain('對應的內部單在回收筒，請先還原，或永久刪除後再重新匯入');

    $adm = impUser('imadm', 'internal', ['project_admin'], $this->p);
    $no = Issue::withTrashed()->find($id)->issue_no;
    ($this->as)($adm)->delete(route('padmin.trash.issues.purge', $id), ['confirm_no' => $no])->assertSessionHas('ok');
    expect(($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $file, 'source' => 'S'])->json('rows.0.state'))->toBe('new');
});

it('requires one valid batch provenance for newly imported issues', function () {
    $file = ($this->upload)($this->pm, impWorkbook($this->rows));
    $pm = ($this->as)($this->pm);
    $input = ['file_id' => $file, 'source' => '來源A', 'rows' => [['no' => '4', 'action' => 'create', 'images' => []]]];
    foreach ([null, 'unknown_import', 'invalid'] as $choice) {
        $pm->postJson('/pm/issues/import/apply', $input + ['provenance' => $choice])->assertUnprocessable();
    }
    $issueId = $pm->postJson('/pm/issues/import/apply', $input + ['provenance' => 'meeting'])->assertOk()->json('done.0.issue_id');
    $pm->get(route('pm.index', ['provenance' => 'meeting']))->assertOk()->assertSee('Excel 來源A #4');
    expect(IssueImportRow::where('issue_id', $issueId)->count())->toBe(1);
});

it('accepts only the standard template', function () {
    $noVersion = ($this->upload)($this->pm, impWorkbook($this->rows, false, null));
    ($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $noVersion, 'source' => 'S'])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '標準匯入範本'));

    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $w = new Writer;
    $w->openToFile($path);
    $w->getCurrentSheet()->setName('問題清單');
    $w->addRow(new Row([new StringCell('序號', null), new StringCell('問題', null)]));
    $w->addNewSheetAndMakeItCurrent()->setName('清單');
    $w->addRow(new Row([new StringCell(IssueImportService::VERSION_LABEL, null)]));
    $w->addRow(new Row([new StringCell(IssueImportService::TEMPLATE_VERSION, null)]));
    $w->close();
    $missing = ($this->upload)($this->pm, $path);
    ($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $missing, 'source' => 'S'])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '缺少必要欄位') && str_contains($m, '標題'));

    ($this->as)($this->pm)->postJson('/pm/issues/import/preview', ['file_id' => $missing, 'source' => 'bad<code>'])->assertStatus(422);
});

it('keeps the import to internal PMs and to the uploader\'s own file', function () {
    $client = impUser('impc', 'client', ['client_pm'], $this->p);
    $other = impUser('impo', 'internal', ['internal_pm'], $this->p);
    $file = ($this->upload)($this->pm, impWorkbook($this->rows, true));

    ($this->as)($client)->get('/pm/issues/import')->assertForbidden();
    ($this->as)($client)->get('/pm/issues/import/template')->assertForbidden();
    ($this->as)($client)->postJson('/pm/issues/import/preview', ['file_id' => $file, 'source' => 'S'])->assertForbidden();
    ($this->as)($other)->postJson('/pm/issues/import/preview', ['file_id' => $file, 'source' => 'S'])->assertStatus(422);
    ($this->as)($other)->get("/pm/issues/import/{$file}/media?p=xl/media/image1.png")->assertNotFound();
    ($this->as)($this->pm)->get('/pm/issues/import')->assertOk()->assertSee('下載標準範本');
    ($this->as)($this->pm)->get('/pm')->assertOk()->assertSee('Excel 匯入');
});

it('audits each confirmed Excel import once, with the exact rows and actions', function () {
    $admin = impUser('import-auditor', 'internal', ['project_admin'], $this->p);
    $first = $this->rows[3];
    $first['no'] = '101';
    $second = $this->rows[3];
    $second['no'] = '202';
    $third = $this->rows[3];
    $third['no'] = '303';
    $file = ($this->upload)($this->pm, impWorkbook([$first]));
    $apply = fn ($id, $rows) => ($this->as)($this->pm)->postJson(route('pm.issues.import.apply'), [
        'file_id' => $id, 'source' => 'QA-BOOK', 'rows' => $rows, 'provenance' => 'meeting',
    ]);
    $row = fn ($no, $action) => ['no' => $no, 'action' => $action, 'images' => []];
    ($this->as)($this->pm)->postJson(route('pm.issues.import.preview'), ['file_id' => $file, 'source' => 'QA-BOOK'])->assertOk();
    expect(ActivityEvent::where('event_type', 'imported')->count())->toBe(0);

    $firstNo = $apply($file, [$row('101', 'create')])->assertOk()->json('done.0.issue_no');
    $first['content'] .= ' amended';
    $file2 = ($this->upload)($this->pm, impWorkbook([$first, $second, $third]));
    $mixed = [$row('101', 'addendum'), $row('202', 'create')];
    $secondNo = $apply($file2, $mixed)->assertOk()->json('done.1.issue_no');
    $apply($file2, $mixed)->assertUnprocessable();
    $thirdNo = $apply($file2, [$row('303', 'create')])->assertOk()->json('done.0.issue_no');
    $third['content'] .= ' amended';
    $file3 = ($this->upload)($this->pm, impWorkbook([$third]));
    $apply($file3, [$row('303', 'addendum')])->assertOk();

    $page = ($this->as)($admin)->get(route('padmin.events'))->assertOk()
        ->assertSeeText('新增 1 張、補充 1 張')->assertSeeText('新增 0 張、補充 1 張')
        ->assertSeeText('QA-BOOK')->assertSeeText($this->pm->name)->assertDontSeeText('歷史匯入');
    $batches = $page->viewData('events')->getCollection()->where('event_type', 'imported')->values();
    expect($batches)->toHaveCount(4)
        ->and(substr_count($page->getContent(), '<details>'))->toBe(4)
        ->and($batches->pluck('payload.batch_id')->unique())->toHaveCount(4)
        ->and($batches[1]->subject_id)->toEqual($batches[2]->subject_id)
        ->and(array_column($batches[2]->payload['rows'], 'issue_no', 'no'))->toBe(['101' => $firstNo, '202' => $secondNo])
        ->and($batches[0]->payload['rows'][0]['issue_no'])->toBe($thirdNo);
    $page->assertSeeInOrder(['101', $firstNo, '補充', '202', $secondNo, '新增']);
    expect(ActivityEvent::where('subject_type', 'issue')->where('event_type', 'imported')->count())->toBe(3);
});

it('rolls back every row and audit event when a later row fails inside the import transaction', function () {
    $file = ($this->upload)($this->pm, impWorkbook([$this->rows[3]]));
    $row = ['no' => '4', 'action' => 'create', 'images' => []];
    ($this->as)($this->pm)->postJson(route('pm.issues.import.apply'), ['file_id' => $file, 'source' => 'rollback',
        'rows' => [$row, $row], 'provenance' => 'meeting'])->assertUnprocessable();
    expect(Issue::count())->toBe(0)->and(IssueImportRow::count())->toBe(0)
        ->and(ActivityEvent::where('event_type', 'imported')->count())->toBe(0);
});

it('lists legacy imports individually with live, trashed or purged numbers, scoped to the project', function () {
    $admin = impUser('legacy-auditor', 'internal', ['project_admin'], $this->p);
    $other = Project::create(['code' => 'OTH', 'ticket_prefix' => 'OTH', 'name' => 'Other']);
    $log = app(EventRepository::class);
    $issues = [];
    foreach (['live', 'trash', 'purge'] as $no) {
        $i = app(IssueService::class)->createInternal($this->pm, $this->p->id, 'pm', ['title' => $no, 'severity' => 2, 'content_html' => 'x']);
        $issues[$no] = $i;
        $log->log($this->p->id, 'issue', $i->id, 'imported', null, null, $this->pm->id, ['source' => 'OLD-REPORT', 'no' => $no]);
        if ($no !== 'live') {
            ($this->as)($this->pm)->delete(route('issues.destroy', $i->id))->assertSessionHas('ok');
        }
    }
    ($this->as)($admin)->delete(route('padmin.trash.issues.purge', $issues['purge']->id), ['confirm_no' => $issues['purge']->issue_no])->assertSessionHas('ok');
    $log->log($other->id, 'issue', $issues['live']->id, 'imported', null, null, $this->pm->id, ['source' => 'OTHER-PROJECT', 'no' => 'foreign']);
    $page = ($this->as)($admin)->get(route('padmin.events'))->assertOk()->assertDontSeeText('OTHER-PROJECT');
    foreach ($issues as $no => $issue) {
        $page->assertSeeText("歷史匯入 · 來源 OLD-REPORT · Excel 序號 {$no} · 內部單號 {$issue->issue_no}");
    }
    expect(substr_count($page->getContent(), '歷史匯入'))->toBe(3);
    foreach (range(1, 50) as $n) {
        $log->log($this->p->id, 'member', $admin->id, 'member_added', null, null, $admin->id);
    }
    ($this->as)($admin)->get(route('padmin.events'))->assertOk()->assertDontSeeText('OLD-REPORT');
    ($this->as)($admin)->get(route('padmin.events', ['page' => 2]))->assertOk()->assertSeeText('OLD-REPORT');
    ($this->as)($this->pm)->get(route('padmin.events'))->assertForbidden();
});
