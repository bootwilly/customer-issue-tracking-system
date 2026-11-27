# PM 內部問題 Excel 匯入（標準範本）

時間：2026-11-27（Asia/Taipei）

## 新增
- `cits/database/migrations/2026_12_03_000100_create_issue_import_rows_table.php`：防重複表 issue_import_rows（project_id + source + source_no 唯一，content_hash 偵測內容變更）。
- `cits/app/Models/IssueImportRow.php`、`cits/app/Repositories/IssueImportRepository.php`。
- `cits/app/Services/Import/XlsxIssueReader.php`：ZipArchive + DOM 直接讀 xlsx。儲存格（含日期格式、shared/inline string）、內部超連結、圖片與圖案（pic/sp/cxnSp/grpSp/graphicFrame，mc:AlternateContent）。頂層物件位置以儲存格錨點為準（a:xfrm 常是舊的），群組內子物件用 chOff/chExt 換算。顏色支援 srgb/scheme(theme)/sys/prst + lumMod/lumOff/shade/tint/alpha。zip 上限：entry 數、解壓總量、單一 XML/圖片大小；拒絕 DOCTYPE。
- `cits/app/Services/Import/IssueImportService.php`：preview（不寫入）/ media / apply（單一交易，createInternal origin pm；changed 列寫 addAddendum）。圖案中心落在圖片內 -> 疊到該圖；其餘圖案依距離分群成獨立圖。明細表第一段文字與問題內容相同時略過；同列多格 -> 表格。
- `cits/app/Services/Import/IssueImportTemplateService.php`：OpenSpout 產生範本（問題清單 / 說明 / 隱藏清單 + template_version），下拉驗證、序號唯一、標題長度。
- `cits/app/Http/Controllers/Pm/PmIssueImportController.php`、`cits/resources/views/pm/issue-import.blade.php`、`cits/resources/js/pm/import.js`（瀏覽器合成 PNG 後走 /uploads/image，context issue:import:{file}:{no}）。
- `cits/tests/Feature/IssueImportTest.php`（7 tests）。

## 修改
- `cits/database/seeders/data/workflow.php`：lookup issue_import_sheet / issue_import_column / issue_import_required / issue_import_status / issue_import_skip、event_label imported、lookup_editable issue_import_status；設定 issue_import.default_severity / max_rows / max_images / max_unzip_mb。
- `cits/app/Services/AttachmentService.php`：importFile()、tempImageIds()。
- `cits/routes/modules/pm.php`：/pm/issues/import、/template、/preview、/{file}/media、/apply。
- `cits/resources/views/pm/index.blade.php`：「Excel 匯入」按鈕。
- `cits/vite.config.js`：加入 resources/js/pm/import.js。
- `docs/manual/04-pm.md`（4.8 Excel 匯入）＋ 重新產生 dist/manual.html、dist/CITS-manual.pdf。

## 驗證
- php artisan test：270/270。pint 通過。vite build 通過。
- 真實共用檔（測試環境記憶體 SQLite）：49 新建、10 略過、0 錯誤；預覽 0.7 秒、建立 1.1 秒。解析器 44 個物件座標與 Excel COM 回報的 Top/Left/Width/Height 一致（0.1pt）。Edge 無頭截圖確認紅框、文字框、流程圖合成正確。

## 部署前
- 先執行 deploy/out/upgrade-20261127-0906.sql（建表 + lookup + 設定），再部署程式；需要 vite build（ASSET_URL=/mescits）。
