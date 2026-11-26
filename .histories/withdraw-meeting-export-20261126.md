# 撤回送測 / 會議篩選與 Excel 匯出 (2026-11-26 14:05)

## 撤回送測（從更新包移出已送測的單）
- 流程設定新增轉換 `test_pending -> dev_doing`，權限 `system`：任何人都看不到按鈕，只由「移出更新包」呼叫（權限另檢查 dev.manage）。
- 選項 `event_label/test_withdrawn` 撤回送測。
- `IssueService::removeFromPackage()` 回傳是否撤回：
  - 開發中：只清更新包（同前）。
  - 有 system 轉換可回 DEV 階段（待測試）：狀態回開發中、清更新包與完工時間、保留 Test 負責人、不加退回次數、記 `test_withdrawn`、通知 Test/DEV 負責人與關注者、重算客戶單結算。
  - 其他（測試中／排程測試／已上線）：拒絕，提示請測試人員判定「仍有異常」。
- `dev/index` 移出確認訊息區分一般移出／撤回送測；成功訊息「已移出更新包並撤回送測，已通知測試人員。」

## 會議清單篩選 + 匯出
- `MeetingService::filters()`：類型（lookup meeting_type）、狀態（workflow meeting）、日期起訖（Asia/Taipei 日期，轉 UTC 查 start_at；起訖顛倒自動交換；不合法值忽略）。`statuses()`、`tabStatuses()`（頁籤 ∩ 狀態）、`range()`。
- `MeetingRepository::list()` 加 type / from / to；`exportParts()` 一次載入多場會議的與會者、討論事項、留言、紀錄。
- `MeetingService::exportRows() / exportOne()`：與畫面相同可見規則（客戶只看分享的會議；紀錄未發布前無紀錄與討論細節；不含內部備註、內部留言、內部單連結）。
- 新增 `app/Services/Export/MeetingExportService.php`：工作表「會議」（+「會議詳細」：紀錄、內部備註、討論事項、留言）與「討論事項」（每項一列）。欄位由 lookup `export_meetings` / `export_meeting_items` 決定。
- 路由 `meetings.export`（依目前頁籤、關鍵字、篩選全部匯出）、`meetings.export-one`（單場）。權限 data.export。
- `meetings/index.blade.php` 篩選列（關鍵字、類型、狀態、日期起訖、清除條件）、匯出 Excel 按鈕；頁籤保留篩選條件。`meetings/show.blade.php` 加「匯出 Excel」。
- Blade：同一檔案混用 `@php(...)` 與 `@php ... @endphp` 會編譯錯誤，`meetings/index`、`dev/index` 改為區塊寫法。

## 測試
- `ProgressPackageTest` 更新（撤回送測、測試中不可移出、再送測沿用 Test）。新增 `MeetingExportTest`（2）。全套 207/207。
- 本機實測：會議日期篩選、頁籤保留條件、全部／單筆匯出 200（xlsx）；撤回送測 FL-13。

## 部署
- 升級 SQL：deploy/out/upgrade-20261126-0559.sql（只有 INSERT IGNORE：新轉換、選項）。
