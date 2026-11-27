# 資安稽核修補第二批（2026-11-27 台北時間）

依據 `docs/security-audit-20261127.md`，接續第一批（`security-audit-fixes-20261127-081226.md`）。

| 項目 | 檔案 | 修改 |
|---|---|---|
| M-01 列出其他客戶帳號 | `ProjectAdminService::candidates`、`ProjectRepository::projectIdsByUser` | 非系統管理員只看得到「還沒加入任何專案」，或「只在自己也是成員的專案」裡的 client 帳號 |
| M-01 鎖定系統管理員 | `ProjectAdminService::members` | 非系統管理員操作時，系統管理員一律 `locked`，不能改角色或移出 |
| M-04 換使用者類型 | `AdminService::updateUser`、`ApiTokenRepository::revokeAllFor`、`WatchRepository::removeAllFor` | 在同一個 transaction 裡，移除所有專案中不符合新類型的角色；改成 client 時一併撤銷 MCP 權杖、移除內部單追蹤 |
| M-04 通知收件人 | `EventRepository::notify`、`WatchService::notifyIssue` | 有專案的通知只寄給該專案成員或系統管理員；內部單通知不寄給 client |
| L-01 密碼變更後其他 session | 新增 `app/Http/Middleware/LogoutAfterPasswordChange.php`，`bootstrap/app.php` 加入 web 群組 | session 保存「使用者 id + 密碼 hash 的 HMAC」；同一個帳號的密碼變了（其他裝置改密碼或用 email 重設）就登出；自己改密碼的那個 session 會在回應時更新，所以不受影響 |
| L-01 MCP | `McpTokenAuth`、`routes/ai.php` | 同一個 IP 每分鐘權杖驗證失敗 20 次後回 429；`/mcp` 加上 ForceHttps |
| M-03 MES 頁面 | `IssueService::checkMesPage`、`MesPageRepository::isActivePage` | 建立和修改內部單時，`mes_page_id` 必須是本專案啟用中的頁面 |
| M-03 由客戶單建立內部單 | `IssueService::createFromTickets` | 先檢查數量和所屬專案，用統一訊息回應，不會帶出其他專案的單號；每張客戶單都執行 `ackRevisions` |
| M-02 檔名 | `AttachmentService::cleanName` | 去掉 `/`、`\` 和控制字元，避免下載時 500 |
| M-02 重複 complete | `AttachmentService::completeUpload`、`UploadSessionRepository::claim` | 以 open→assembling 原子性搶占 session，失敗時改回 open 可以重試 |

測試：新增 `tests/Feature/SecurityAuditBatch2Test.php`（7 個）；`NotifyTest` 有一個測試改成先把收件人加入專案。全部 250 個通過。
