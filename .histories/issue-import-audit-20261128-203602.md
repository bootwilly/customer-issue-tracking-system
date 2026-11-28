# Excel 匯入與專案異動紀錄

2026-11-28 20:36（台北）

「專案管理 → 異動紀錄」依成功確認次數列出 Excel 匯入，顯示時間、操作者、來源代碼、新增及補充張數。展開可核對每一列的 Excel 序號、內部單號與動作。清單每頁 50 筆，可往前查歷史資料。

- 沿用 events 與既有「由 Excel 匯入」事件名稱，每次確認取得獨立批次識別。批次明細記錄當時的單號。批次、逐張建單事件、補充說明和匯入列在同一個交易內完成。
- 無批次識別的舊建單事件逐張呈現。單號由同專案的內部單或永久刪除紀錄取得。舊單的補充說明未記錄匯入批次，仍可在單據內查閱。
- 沿用專案管理權限，只向可檢視內部資料的人員顯示匯入明細。資料表及正式站資料毋須調整。

## 修改範圍

- cits/app/Services/Import/IssueImportService.php
- cits/app/Repositories/EventRepository.php
- cits/app/Services/ProjectAdminService.php
- cits/resources/views/project-admin/events.blade.php
- cits/tests/Feature/IssueImportTest.php
- docs/manual/04-pm.md、docs/manual/08-project-admin.md 與 docs/manual/dist 的 HTML、PDF
- PROJECT_CONTEXT.md、SESSION_LOG.md

## 驗證

完整 SQLite 記憶體測試通過 330 項、6,101 個斷言。新增測試涵蓋混合批次、純補充、同檔分次確認、重試、交易回滾、舊單刪除後的單號、專案範圍、權限及分頁。修改的 PHP 與 Blade 通過 Pint。Vite 子路徑建置成功，離線 HTML 與 PDF 已重建。
