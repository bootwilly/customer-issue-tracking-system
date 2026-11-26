<?php

namespace Database\Seeders;

use App\Models\MesPage;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Services\TicketService;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/** LOCAL ONLY: demo data for visual review. Never part of install.sql. Password for all demo users: 123456 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $p = Project::firstOrCreate(['code' => 'MES'], ['name' => 'MES 製造執行系統', 'ticket_prefix' => 'MES', 'status' => 'active']);
        $mk = function (string $acc, string $name, string $type, array $roles) use ($p) {
            $u = User::firstOrCreate(['account' => $acc], ['name' => $name, 'email' => "$acc@demo.test", 'password' => '123456', 'user_type' => $type, 'status' => 'active']);
            $p->members()->syncWithoutDetaching([$u->id]);
            app(PermissionRegistrar::class)->setPermissionsTeamId($p->id);
            $u->syncRoles($roles);
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);

            return $u->fresh();
        };
        $cpm = $mk('cpm1', '王小明（客戶PM）', 'client', ['client_pm']);
        $mk('cview1', '李小華（客戶View）', 'client', ['client_view']);
        $pm = $mk('pm1', '陳經理（內部PM）', 'internal', ['internal_pm']);
        $dev = $mk('dev1', '林工程師（DEV）', 'internal', ['dev']);
        $test = $mk('test1', '張測試（Test）', 'internal', ['test']);

        $prod = MesPage::firstOrCreate(['project_id' => $p->id, 'name' => '生產管理', 'node_type' => 'folder']);
        $wo = MesPage::firstOrCreate(['project_id' => $p->id, 'page_path' => '~/Production/WorkOrder.aspx'], ['parent_id' => $prod->id, 'node_type' => 'page', 'name' => '工單維護', 'page_file' => 'WorkOrder.aspx']);
        $rep = MesPage::firstOrCreate(['project_id' => $p->id, 'page_path' => '~/Production/Report.aspx'], ['parent_id' => $prod->id, 'node_type' => 'page', 'name' => '報工作業', 'page_file' => 'Report.aspx']);
        $qc = MesPage::firstOrCreate(['project_id' => $p->id, 'name' => '品質管理', 'node_type' => 'folder']);
        $ins = MesPage::firstOrCreate(['project_id' => $p->id, 'page_path' => '~/Quality/Inspection.aspx'], ['parent_id' => $qc->id, 'node_type' => 'page', 'name' => '品檢報表', 'page_file' => 'Inspection.aspx']);

        if ($p->id && \App\Models\ClientTicket::where('project_id', $p->id)->exists()) {
            return;
        }
        $ts = app(TicketService::class);
        $is = app(IssueService::class);
        $new = fn ($title, $type, $cat, $page, $html) => $ts->create($cpm, $p->id, ['ticket_type' => $type, 'anomaly_category' => $cat, 'mes_page_id' => $page, 'title' => $title, 'content_html' => $html]);

        $new('工單維護頁「儲存」按鈕錯位', 'anomaly', 'ui', $wo->id, '<p>在 1366×768 解析度下，儲存按鈕被表格蓋住，無法點擊。</p>');
        $t2 = $new('報工數量輸入後未即時更新合計', 'anomaly', 'function', $rep->id, '<p>輸入數量後需重新整理頁面合計才會更新。</p>');
        $t3 = $new('品檢報表新增匯出 Excel', 'requirement', null, $ins->id, '<p>希望品檢報表可以匯出 Excel，欄位與畫面相同。</p>');
        $t4 = $new('報工頁面日期格式錯誤', 'anomaly', 'ui', $rep->id, '<p>日期顯示為 MM/DD/YYYY，應為 YYYY/MM/DD。</p>');

        $ts->accept($pm, $t2->id, $p->id);
        $i2 = $is->createFromTickets($pm, $p->id, [$t2->id], ['title' => '報工合計未即時更新', 'content_html' => $t2->content_html, 'severity' => 4, 'process_type' => 'bug', 'mes_page_id' => $rep->id]);
        $is->transition($pm, $i2->id, $p->id, 'dev_todo', ['assignee_dev_id' => $dev->id]);
        $is->transition($dev, $i2->id, $p->id, 'dev_doing', ['planned_due' => now()->addDays(5)->toDateString()]);
        $ts->addComment($cpm, $t2->id, $p->id, 'supplement', '<p>補充：只有在 Chrome 會發生，Edge 正常。</p>');

        $ts->accept($pm, $t3->id, $p->id);
        $i3 = $is->createFromTickets($pm, $p->id, [$t3->id], ['title' => '品檢報表匯出 Excel', 'content_html' => $t3->content_html, 'severity' => 2, 'process_type' => 'feature', 'mes_page_id' => $ins->id]);
        $is->transition($pm, $i3->id, $p->id, 'dev_todo', ['assignee_dev_id' => $dev->id]);
        $pkg = $is->createPackage($dev, $p->id, 'MES_v2.3.1_20261120');
        $is->transition($dev, $i3->id, $p->id, 'dev_doing', ['planned_due' => now()->subDay()->toDateString()]);
        $is->transition($dev, $i3->id, $p->id, 'test_pending', ['package_id' => $pkg, 'assignee_test_id' => $test->id]);
        $is->transition($test, $i3->id, $p->id, 'test_doing');
        $is->transition($test, $i3->id, $p->id, 'release_pending', ['release_planned_at' => now()->toDateString()]);
        $is->transition($test, $i3->id, $p->id, 'released');

        $ts->accept($pm, $t4->id, $p->id);
        $is->createInternal($pm, $p->id, 'pm', ['title' => '統一全系統日期格式', 'content_html' => '<p>內部改善：所有頁面日期改為 YYYY/MM/DD。</p>', 'severity' => 3, 'process_type' => 'ui_change']);
        $is->createInternal($test, $p->id, 'test', ['title' => '工單列表分頁後排序失效', 'content_html' => '<p>測試中發現：切換到第 2 頁後排序回到預設。</p>', 'severity' => 3, 'assignee_dev_id' => $dev->id]);
    }
}
