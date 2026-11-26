<?php

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Services\Export\DetailBlock;
use App\Services\Export\ExportBook;
use App\Services\Export\ExportColumnService;
use App\Services\Export\ExportLink;
use App\Services\Export\ExportSheet;
use App\Services\Export\HtmlToText;
use App\Services\Export\XlsxExportService;
use App\Services\WorkflowService;

function exXml(string $path, string $entry): string
{
    $z = new ZipArchive;
    $z->open($path);
    $s = (string) $z->getFromName($entry);
    $z->close();

    return $s;
}

it('writes list, detail and info sheets with two-way links and guards formulas', function () {
    $admin = User::where('account', 'admin')->first();
    $sheet = new ExportSheet('客戶單', ['no' => '單號', 'title' => '標題', 'due' => '期限'], [
        ['no' => 'A-1', 'title' => '=1+1 危險', 'due' => new DateTime('2026-11-30')],
        ['no' => 'A-2', 'title' => '第二筆', 'due' => null],
    ], [
        new DetailBlock('A-1 第一筆', ['狀態' => '已提交'], ['內容' => ['第一行', '第二行'], '留言' => []]),
        new DetailBlock('A-2 第二筆', ['狀態' => '處理中'], ['內容' => [new ExportLink('附件', 'https://x.test/a/1')]]),
    ], 'title');
    $path = tempnam(sys_get_temp_dir(), 'ext');
    app(XlsxExportService::class)->write(new ExportBook('t', 'x.xlsx', [$sheet], ['狀態' => '全部']), $path, $admin, 'P');

    $wb = exXml($path, 'xl/workbook.xml');
    expect($wb)->toContain('name="客戶單"')->toContain('name="客戶單詳細"')->toContain('name="匯出資訊"');
    $list = exXml($path, 'xl/worksheets/sheet1.xml');
    // block 1 starts at row 1; block 2 = 1 + (title + 1 field + blank) + (heading + 2 lines) = row 7 (empty 留言 skipped)
    expect($list)->toContain("location=\"'客戶單詳細'!A1\"")->toContain("location=\"'客戶單詳細'!A7\"");
    $detail = exXml($path, 'xl/worksheets/sheet2.xml');
    expect($detail)->toContain("location=\"'客戶單'!B2\"")->toContain("location=\"'客戶單'!B3\"");
    expect($list)->toContain("'=1+1 危險")->not->toContain('<f>');
});

it('enforces the adjustable max rows', function () {
    $x = app(XlsxExportService::class);
    expect($x->maxRows())->toBe(5000);
    app(SettingRepository::class)->set('export.max_rows', '3');
    $x->assertWithinLimit(3);
    expect(fn () => $x->assertWithinLimit(4))->toThrow(BusinessException::class);
});

it('selects export columns from lookups within the whitelist', function () {
    $cols = app(ExportColumnService::class)->columns('client_tickets', ['title', 'ticket_no', 'evil']);
    expect($cols)->toBe(['ticket_no' => '單號', 'title' => '標題']);
});

it('converts editor html to lines keeping image and link urls', function () {
    $lines = HtmlToText::lines('<p>甲&amp;乙</p><p><img src="/attachments/5"></p><ul><li>一</li></ul><p><a href="https://e.test/x">連結</a></p>');
    expect($lines[0])->toBe('甲&乙')->and(implode("\n", $lines))->toContain('[圖片] '.url('/attachments/5'))->toContain('• 一')->toContain('連結（https://e.test/x）');
});

it('lets only admins change the export limit', function () {
    $admin = User::where('account', 'admin')->first();
    $u = User::create(['account' => 'exu', 'name' => 'exu', 'email' => 'exu@t.test', 'password' => '123456', 'user_type' => 'internal', 'status' => 'active']);
    $this->actingAs($u)->get('/admin/settings/export')->assertForbidden();
    $this->actingAs($admin)->get('/admin/settings/export')->assertOk()->assertSee('5000');
    $this->actingAs($admin)->put('/admin/settings/export', ['max_rows' => 1200])->assertRedirect(route('admin.export.edit'));
    expect(app(WorkflowService::class)->setting('export.max_rows'))->toBe('1200');
});
