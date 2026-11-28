# 測試管理派工時間與結案收尾

2026-11-28 18:39（台北）

- 測試與上線、已退回／新發現、結案收尾及各自的 Excel 都顯示測試派工時間與來源。現行清單取最近的 PM 派測或開發送測事件；收尾紀錄取該輪送出前最後一次送測。同秒事件依事件 ID 排序。
- 派工來源依工作流程轉換判定。單筆與更新包批次共用同一規則，排程、開始測試、退回、撤回或重新指派人員都不構成新的送測事件。尚未送測或找不到紀錄時顯示「—」。
- 測試與開發管理共用收尾清單的篩選、進度、權限、分頁和匯出。開發管理沿用第一次 PM 派到待開發的時間。

## 檔案

- 資料查詢：`cits/app/Repositories/EventRepository.php`、`IssueCloseRepository.php`。
- 服務：`cits/app/Services/IssueDispatchService.php`、`IssueCloseListService.php`、`Dev/DevService.php`、`Test/TestService.php`、`Export/IssueExportService.php`。
- 畫面：`cits/resources/views/test/index.blade.php`、`dev/index.blade.php`、`dev/_table.blade.php`。
- 回歸測試：`cits/tests/Feature/IssueExportTest.php`。
- 手冊：`docs/manual/06-test.md`、`07-common.md`、`dist/manual.html`、`dist/CITS-manual.pdf`。專案紀錄同步至 `PROJECT_CONTEXT.md` 與 `SESSION_LOG.md`。

## 驗證

- 完整 SQLite 測試通過 313 個、5,737 個斷言，涵蓋同秒跨輪次、雙角色來源、跨專案、撤回再送、分頁與實際 XLSX。
- 異動 PHP 通過 Pint，Vite 使用 /mescits 建置並產生 manifest。離線 HTML 與 PDF 已核對新章節文字。
