# 資安稽核修補（2026-11-27 08:12 台北時間）

依據：`docs/security-audit-20261127.md`（另一個 agent 的稽核報告）。先逐項對照程式碼確認屬實，再修補 H-01～H-06、M-02、M-08。

| 項目 | 檔案 | 修改 |
|---|---|---|
| H-03 Host 竄改 | `cits/bootstrap/app.php` | `trustProxies(at: '*', headers: X_FORWARDED_FOR \| X_FORWARDED_PROTO)`，不再採用連線端送的 X-Forwarded-Host／Port／Prefix |
| H-03 重設冷卻 | `cits/app/Services/PasswordResetService.php` | `createToken()` 前先檢查 `recentlyCreatedToken()`（60 秒內不重發） |
| H-01 跨專案引用留言 | `PmTicketController.php`、`IssueService::quoteComment`、`IssueRepository::linkedTicketComment` | 留言必須屬於路由上的客戶單，客戶單必須在本專案而且已連結到該內部單；只能引用客戶留言 |
| H-02 行內 JS 跳脫 | `flows/show`（4 處）、`admin/lookups/index`、`meetings/show`、`project-admin/members`、`mes/index` | `confirm('…{{ }}…')` 改成 `confirm(@js(...))`，`x-data` 改成 `@js(...)` |
| H-04 會議洩漏內部單號 | `MeetingController::itemJson`、`MeetingService::exportParts`、`MeetingExportService` | 客戶拿到的 JSON 不含 issue_no／issue_url；紀錄尚未發布時，匯出不帶負責人、期限、狀態、未完成數 |
| H-05 圖片同網域任意路徑 | `SanitizerService`（`ImgSrcAttributeSanitizer`） | 只放行 `(/prefix)*/attachments/{id}`（相對網址或同主機的 http/https，不能帶 query／fragment／帳密） |
| H-05 GET 鎖單 | `Client/TicketController::edit` | `Sec-Fetch-Dest` 不是 document／iframe 時回 403，不取得編輯鎖 |
| H-06 drawio | `Flow/DrawioParser.php` | 解析後用 DOM 再檢查一次（依 localName 擋 script、SMIL 等元素，擋 on* 屬性，解碼後的 URL 不可以是 javascript:／vbscript:，data: 只允許 data:image/）；`gzinflate` 加上 `$maxBytes` 上限；`load()` 拒絕 doctype |
| M-02 附件權限 | `routes/modules/upload.php`、`AttachmentService::canView`、`AttachmentRepository::projectIsActive`、新增 `config/attachments.php` | 上傳和下載都掛 `active`；已綁定的附件需要「啟用中的專案的成員」加上 `config('attachments.view_permissions')` 對應的檢視權限；上傳者本人只在檔案還是暫存時可以直接看 |
| M-08 內部單編輯漏綁附件 | `IssueController::fields`、`IssueService::updateFields` | 接收 `draft`；內文更新後呼叫 `bind()` 和 `clearDraft()` |

測試：新增 `cits/tests/Feature/SecurityAuditTest.php`（13 個），全部 243 個通過。

正式站唯讀檢查：既有內文的 `<img>` 共 3 筆，都是 `/mescits/attachments/N`，收緊規則後不會被移除。
