# 處理類型選項管理

時間：2026-11-26（Asia/Taipei）

## 新增
- `cits/app/Repositories/LookupAdminRepository.php`：選項 CRUD、使用筆數（分類對應資料表欄位）。
- `cits/app/Services/LookupAdminService.php`：新增（自動代碼）、改名、上下移、啟用／停用（至少保留一個可選）、刪除（無使用才可刪）。
- `cits/app/Http/Controllers/Admin/LookupController.php`、`cits/routes/modules/lookup_admin.php`、`cits/resources/views/admin/lookups/index.blade.php`
- `cits/tests/Feature/LookupAdminTest.php`

## 修改
- `cits/app/Repositories/WorkflowRepository.php`：`lookupsAll()`；`cits/app/Services/WorkflowService.php`：`lookupMapAll()`。
- `cits/app/Services/Export/IssueExportService.php`：處理類型名稱含已停用項目。
- `cits/app/Services/Pm/PmQueryService.php`：編輯時不可改選已停用／不存在的處理類型（保留原值可以）。
- `cits/resources/views/issues/show.blade.php`：目前值已停用時，下拉保留並標「（已停用）」。
- `cits/resources/views/admin/_tabs.blade.php`：新增「選項管理」分頁。
- `cits/database/seeders/data/workflow.php`：lookup `lookup_editable / process_type / 處理類型`。
- `cits/database/seeders/DatabaseSeeder.php`：可管理分類只補缺、不覆蓋名稱與排序。

## 驗證
- Pest 172/172；瀏覽器實測新增項目。未部署。
