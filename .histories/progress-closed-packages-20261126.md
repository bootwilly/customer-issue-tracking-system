# 客戶處理進度 / PM 看得到客戶結案 / 更新包套入多張單 (2026-11-26 12:30)

需求（使用者）：
1. 客戶問題看不到目前問題接單後處理進度
2. 問題管理看不到客戶針對問題已結案的狀態
3. 開發管理 更新包需要可以套入多個修正問題

## 1. 客戶處理進度
- 新增 `app/Services/Client/ClientProgressService.php`：依客戶單狀態 + 關聯內部單（排除 is_excluded）算出客戶看得到的進度；多張內部單取最慢的一張。不輸出內部單號／內部狀態名。
  - `release_planned_at`（尚未上線的內部單）取最晚日期，顯示為「預計 yyyy-mm-dd 更新」。
- 新增 lookup `client_progress`（code = 客戶單狀態 / 內部單 stage / 已上線狀態 code）：已提交、已接收・評估中、修正中、測試中、待更新正式環境、已更新正式環境、待您確認結案、已結案。
- `TicketRepository::linkedIssueStates()`。
- `Client/TicketController` index/show 傳 `progress`（show 另傳 `progressSteps`）。
- `client/show.blade.php` 標題下加進度條；`client/index.blade.php` 加「處理進度」欄。

## 2. PM 看得到客戶結案
- `TicketService::close()`：客戶結案時通知接收者 + 有 ticket.settle 權限者 + 關注者（「客戶已結案 {單號}」）。
- `WorkflowService::ticketScopeCodes() / ticketScope() / isDefaultTicketScope()`：客戶單清單範圍（client_tab 或 all）。
- `PmQueryService::board`、`TicketExportService::pmTicketsSheet`、`PmController::filters`（新參數 `tt`）、匯出資訊列。「只看需處理」只在預設範圍（未結案）生效。
- `SavedQueryService` pm 範圍白名單加 `tt`。
- `pm/index.blade.php`：範圍下拉（未結案／待結案／已結案／取消／全部）、結案時間欄；內部單清單「客戶單」欄改為連結 + 客戶單狀態標籤（`IssueRepository::paginate` 多載入 tickets.status）。

## 3. 更新包套入多張單
- `IssueService::packageCandidateCodes() / packageBoard() / addToPackage() / removeFromPackage()`：DEV/TEST 階段未結束的單可批次套入；已上線（released_at）的更新包不能變更；已上線的單不能移出。事件 `package_set` / `package_unset`（lookup event_label）。
- 送測時 `package_id` 檢查同專案；`issues/show.blade.php` 送測下拉預設選取單上已套用的更新包。
- `IssueRepository::issuesOfPackages() / inStatuses()`；`DevService`、`DevController`、`routes/modules/dev.php`（`dev.packages.issues.add` / `dev.packages.issues.remove`）。
- `dev/index.blade.php`：每個更新包列出包含的單（可 ✕ 移出）、「套入問題單…」勾選清單。

## 測試
- 新增 `tests/Feature/ProgressPackageTest.php`（4 tests）。全套 205/205 通過。
- 本機瀏覽器 / curl 實測：PM 範圍篩選、更新包批次套入、客戶端進度顯示。

## 部署注意
- 無新 migration；新 lookup 列（client_progress、event_label package_set/package_unset）由升級 SQL 的 INSERT IGNORE 帶入。
