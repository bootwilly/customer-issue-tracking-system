# CITS 平台與開發紀錄

> 客戶問題追蹤系統（Customer Issue Tracking System）。最後更新：2026-11-26。
> 這份文件給接手開發的人（人或 AI）。先讀這份，再讀 `PROJECT_CONTEXT.md`（長期規則）、`SESSION_LOG.md`（逐次紀錄）、`HANDOVER.md`（流程細節）。

---

## 1. 平台概要

| 項目 | 內容 |
|---|---|
| 正式站 | https://example.com/mescits/ |
| 主機 | iFastNet 付費共享主機（cPanel），無 SSH，有 cron，沒有常駐的 queue worker |
| 公開目錄 | `/home/cpuser/public_html/mescits`（WebDAV profile `webdav-demo`） |
| 程式核心 | `/home/cpuser/app/mescits`（WebDAV profile `webdav-app`，不在 public_html 內） |
| 資料庫 | MariaDB 11.4，`cpuser_mescits`。**sql_mode = ''**（varchar 太長會被直接截斷，不會報錯） |
| DB 連線上限 | max_user_connections = 38（一次性腳本跑完每個請求都要 `$app['db']->disconnect()`） |
| PHP | 8.5（網頁和 CLI 都是；CLI 路徑 `/usr/local/bin/php`） |
| 時區 | 程式和資料庫都用 UTC；畫面顯示 Asia/Taipei（`tw()` helper）。正式站 log 的時間也是 UTC |
| 郵件 | SMTP `mail.example.com:465`，加密方式選 SSL，帳號 `mescits_bot@example.com`（在 系統管理 → 郵件設定 填寫） |
| 排程 | cPanel cron 每 5 分鐘：`*/5 * * * * /usr/local/bin/php /home/cpuser/app/mescits/artisan schedule:run >> /dev/null 2>&1` |

### 排程內容（`cits/routes/console.php`）
| 指令 | 頻率 | 說明 |
|---|---|---|
| `cits:send-outbox` | 每分鐘（實際每 5 分鐘） | 寄出 Email 佇列 |
| `queue:work --stop-when-empty --max-time=50` | 每分鐘（實際每 5 分鐘） | 背景工作 |
| `cits:cleanup-temp` | 每天 00:00 UTC | 清理暫存上傳 |
| `cits:needinfo-remind` | 每天 01:30 UTC（台北 09:30） | 補充資料逾期提醒 |

**規則：cron 是每 5 分鐘跑一次，所以每日排程的分鐘數必須是 5 的倍數，否則永遠不會執行。**

---

## 2. 技術棧與架構

- Laravel 13、Blade、Alpine.js、Tailwind 4、Vite（rolldown）、Pest 測試。
- 權限套件 spatie/laravel-permission，**team = project_id**：角色是全域的，指派給使用者時以專案為單位。
- 本機開發用 SQLite；正式站用 MariaDB。

### 分層規則（必須遵守）
```
Controller → Service（BLL，業務邏輯） → Repository（資料存取）
```
- **BLL（Service）不可直接操作資料庫**：不寫 SQL、不用 DB facade、不碰 Eloquent 查詢，一律透過 Repository。
- **不可寫死業務值**：狀態碼、標籤、選項、欄位清單都從資料表來，例如 `lookup_items`、`workflow_statuses`、`workflow_transitions`、`system_settings`。
  種子資料都定義在 `cits/database/seeders/data/workflow.php`。
- 權限判斷一律用 `WorkflowService::can()`。系統管理員由 `Gate::before` 放行，但權限碼 `system` 對任何人都回傳 false。

### 身分判斷
| 方法 | 用途 |
|---|---|
| `User::seesInternal()` | 可以看內部資料（管理員或內部人員） |
| `User::actsAsClient()` | 可以使用客戶端功能（管理員或客戶） |
| `User::isClient()` | 只用在資料語意上，例如首頁導向、已讀紀錄、會議是否由客戶發起、通知的收件對象 |

---

## 3. 功能模組

| 模組 | 路徑／檔案 | 重點 |
|---|---|---|
| 客戶問題（客戶端） | `/tickets`，`Client/TicketController`、`TicketService` | 開單、修訂（有版本）、留言、取消、結案、回報仍有異常；處理進度條；可以篩選「需要補充資料」 |
| 問題管理（PM） | `/pm`，`Pm/PmController`、`PmQueryService` | 客戶單清單（範圍參數 `tt`：未結案／待結案／已結案／取消／全部）、內部單清單（階段、狀態、來源、標籤篩選）、批次排入會議、匯出 |
| 客戶單（PM 檢視） | `/pm/tickets/{id}` | 接收、回覆、處置、要求客戶補充資料、關注、建立或連結內部單 |
| 內部單 | `/issues/{id}`，`IssueService` | 狀態流程、欄位權限、標籤、相依關係、關注、延期申請、會議連結 |
| 開發管理 | `/dev`，`DevService` | 清單、排程，以及更新包：一次套入多張單即送測並跳出指定測試人員；移出更新包即撤回送測 |
| 測試管理 | `/test`，`TestService` | |
| 時序總覽、報表 | `/timeline`、`/reports` | |
| MES 頁面 | `/mes` | 功能頁面樹 |
| 流程圖 | `/flows`，`Flow/*` | drawio / mermaid，有草稿和版本 |
| 會議 | `/meetings`，`MeetingService` | 討論事項、紀錄、內部備註、留言、分享給客戶、發布、下次會議、.ics；篩選類型／狀態／日期；**匯出 Excel（全部或單筆）** |
| 專案管理 | `/project-admin` | 成員與角色、資源回收筒、操作紀錄 |
| 系統管理 | `/admin` | 使用者、專案、選項管理、郵件設定、匯出設定 |
| 操作手冊 | `/manual`，`ManualService` | 來源是 `docs/manual/*.md` 和 `img/`；部署時打包進 `resources/manual`；依角色過濾章節 |
| 通知 | `/notifications`，`EventRepository::notify`、`OutboxService` | 站內通知加 Email 佇列 |
| 協作 | `routes/modules/collab.php`，`Services/Collab/*` | 補充資料、關注、重複單提示、我的查詢、標籤、相依關係 |
| API／MCP | `Services/Mcp`、API Token | |

### 角色（`workflow.php` 的 roles）
`client_pm`、`client_view`、`internal_pm`、`dev`、`test`、`project_admin`。中文名稱在 lookup `role_label`。
勾選「系統管理員」的帳號在客戶端和內部端都有完整權限。

---

## 4. 主要流程

### 客戶單狀態
`submitted 已提交 → in_progress 處理中 → pending_close 待結案 → closed 已結案`，任何階段都可能變成 `cancelled 取消`。
- 內部單全部上線後，系統自動轉為待結案並通知客戶。
- 內部單全部取消時，通知 PM 處置。
- 客戶回報仍有異常：從待結案回到處理中，PM 再開新的內部單。

### 內部單狀態（stage）
```
PM：new 新建 / discussing 待討論
DEV：dev_todo 待開發 → dev_doing 開發中（必填 planned_due）；test_found 新發現；test_failed 仍有異常
TEST：test_pending 待測試 → test_doing 測試中 / test_scheduled 排程測試
RELEASE：release_pending 待更新正式環境 → released 已更新
排除：cancelled 取消、wont_fix 不處理（連結了客戶單時必填給客戶的說明）
```
- 開發中 → 待測試：必填 `package_id` 和 `assignee_test_id`；單上已經有 Test 負責人時沿用。
- **待測試 → 開發中**：權限碼是 `system`，不會出現按鈕，只由「移出更新包」觸發（撤回送測，不加退回次數）。
- 測試階段退回開發階段：`rework_count + 1`。
- 被設成「需等待」的單，要等上游單上線或排除後，才能從 PM 派給 DEV。

### 客戶看到的處理進度
lookup `client_progress`：已提交 › 已接收・評估中 › 修正中 › 測試中 › 待更新正式環境 › 已更新正式環境 › 待您確認結案 › 已結案。
有多張關聯內部單時，以最慢的那張為準（排除的不算）。客戶看不到內部單號。

---

## 5. 資料表重點

- 主要：`client_tickets`、`ticket_revisions`、`ticket_comments`、`ticket_reopens`、`issues`、`ticket_issue_links`（多對多）、`packages`、`due_change_requests`、`activity_events`、`user_notifications`、`outbox`、`attachments`。
- 協作：`ticket_needinfo`、`ticket_watchers`、`issue_watchers`、`saved_queries`、`issue_tags`、`issue_dependencies`。
- 會議：`meetings`、`meeting_attendees`、`meeting_items`、`meeting_comments`、`meeting_reads`。
- 流程圖：`project_flows`、`project_flow_revisions`。
- 設定：`workflow_statuses`、`workflow_transitions`、`field_edit_rules`、`lookup_items`、`system_settings`。
- 匯出欄位由 lookup `export_<清單>` 決定，例如 `export_pm_tickets`、`export_meetings`、`export_meeting_items`，可以在選項管理調整。

---

## 6. 通知與 Email

所有通知都透過 `WatchService::notifyTicket / notifyIssue`：
- 內部人員的連結是 `/pm/tickets/{id}`，客戶的連結是 `/tickets/{id}`。
- 會同時通知關注者，不通知做這個動作的人，也不通知已不在專案的人（管理員例外）。
- 同一事件的收件人上限是 `watch.max_recipients`（50）。

Email 只在以下條件都成立時才寄：郵件設定已啟用、使用者帳號是啟用中、個人設定沒關掉 Email 通知（`email_notify`）。
信件會先排進 `outbox`，再由排程寄出。完整的事件清單在 2026-11-26 的對話紀錄中有整理，會議目前不發任何通知。

---

## 7. 開發環境（本機，Windows）

- PHP：`/c/Users/<you>/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.NTS.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe`
- 測試：在 `cits` 目錄執行 `php vendor/bin/pest`，目前 207 個全過。
  - `SeedDataLengthTest` 會把所有 migration 以 MariaDB DDL 的 pretend 模式編譯，並檢查 `workflow.php` 的種子值有沒有超過欄位長度。
  - 因此資料 migration 必須用 `DB::statement` 寫原生 SQL。用 query builder 寫的 update 在 pretend 模式下會嘗試連線而失敗。
- 前端建置：在 `cits` 執行 `ASSET_URL=/mescits npm run build`。
- 本機站（用測試資料庫）：`DB_CONNECTION=sqlite DB_DATABASE=<某個 .sqlite> php artisan serve --port=8011`。
- 編輯陷阱：
  - Bash heredoc 會吃掉反斜線。要做多段字串替換時，用寫檔工具把 Python 腳本寫成檔案再執行。
  - 同一個 Blade 檔案不要混用 `@php(...)` 和 `@php ... @endphp`，會編譯錯誤。
  - `HANDOVER.md` 是 CRLF 換行；`AGENTS.md`、`PROJECT_CONTEXT.md`、`SESSION_LOG.md` 只能用 ASCII 字元。
  - 替換腳本中，如果 old 字串剛好是 new 字串的前綴，重跑會插入兩次。

---

## 8. 資料庫升級（AI 不直接改正式站資料庫）

1. 產生升級 SQL：`php deploy/make_upgrade_sql.php --applied=<已套用的 migration 名稱，逗號分隔>`，輸出到 `deploy/out/upgrade-<日期時間>.sql`。
   - 內容：未套用的 migration DDL、對應的 migration 紀錄，以及 lookup／setting／transition／權限的 INSERT IGNORE（已存在的資料不會被改）。
   - **要修改既有資料列，只能寫 migration**，因為 INSERT IGNORE 不會更新已存在的資料。
   - 正式站目前已套用到 `2026_11_30_000200_create_collab_tables`。
   - 2026-11-26 已把 `deploy/out/install.sql` 重新產生為最終版（含全部 14 個 migration），所以之後產生升級 SQL **不用再帶 `--applied`**。完整流程見 `docs/DEPLOYMENT_GUIDE.md`。
2. 交給使用者在 phpMyAdmin 執行。**要先在左側點選 `cpuser_mescits`**，曾經發生過跑到別的資料庫。
3. 用一次性的唯讀 SELECT 腳本確認結果。腳本範本在本次 session 的暫存資料夾（scratchpad 底下的 `vq/`）：
   - 用 token 保護，執行一次就自刪。
   - 只能有 SELECT 和 SHOW。

---

## 9. 部署流程（凍結快照）

1. **建立快照**（PowerShell robocopy）：
   - 複製 `cits`，排除 vendor、node_modules、storage、.git、public/drawio、public/build。
   - 複製 `docs/manual`（排除 dist）和 `deploy/build.py`。
   - **vendor 要實際複製**；用 junction 的話，Pest 會把測試綁到原本的資料夾。node_modules 可以用 junction。
   - 建立空的 storage 子目錄。
2. 記錄快照的 md5 基準，在快照內執行 `ASSET_URL=/mescits npm run build`，再跑完整測試。
3. 確認 md5 沒有變動後，在快照內執行 `python -I deploy/build.py --no-npm`，產生 `deploy/out/app.zip` 和解壓程式 `x_*.php`，`extractor.txt` 會寫明檔名和 token。
   - 如果上次失敗留下 `deploy/out/stage`，會出現 WinError 145，刪掉 stage 再重跑。
   - 把這次的 app.zip 和上一次部署的比對，確認只有預期的檔案不同。
   - 手冊有人正在改時，換回上一次部署的手冊，避免推上寫到一半的內容。
4. **上傳順序**（`python -I deploy/mcp_call.py upload_items '{"profile":...,"paths":[絕對路徑],"remoteDirectory":"/...","overwrite":true}'`）：
   1. 新的 `/build/assets`：先用 `browse_remote` 列出遠端已有的檔案名，只傳缺的。
   2. `app.zip` 傳到 webdav-app 的 `/`，解壓程式傳到 webdav-demo 的 `/`。
   3. 呼叫 `https://example.com/mescits/<extractor.txt 第 1 行>?t=<第 2 行>`。`.env` 會保留，zip 和解壓程式都會自刪。
   4. 最後才傳 `manifest.json` 和 `fonts-manifest.json` 到 `/build`。
5. **驗證**：
   - manifest 的 md5 一致，manifest 裡的每個 asset 用 HEAD 檢查都回 200。
   - 用 `download_items '{"profile","paths":[...],"localDirectory","overwrite"}'` 下載幾個關鍵檔案比對 md5。
   - 有新權限或 status map 變動時，跑一次性腳本清快取：`PermissionRegistrar::forgetCachedPermissions()`、`Cache::forget('cits.workflow_status_map')`。
   - 登入測試：一次性腳本 `zs_*.php`，範本是 scratchpad 的 `smoke_tpl.php`。它偽造 file session，以管理員、內部、客戶身分 GET 各頁，只印出狀態碼。
     - 必須設 `SCRIPT_NAME=<app.url 路徑>/index.php`，否則所有路由都會 404。
     - 每個請求後要 disconnect。
     - 跑完會刪掉自己和產生的 session 檔。
   - 檢查 `/storage/logs` 有沒有新的 log。
6. 寫進 `SESSION_LOG.md`。

> 注意：scratchpad 會被清掉。要長期保留的話，把 `smoke_tpl.php` 和唯讀檢查腳本的範本移到 `deploy/tools/`（目前還沒做）。

---

## 10. 2026-11-26 開發紀錄（這一輪全部已上線）

| 批次 | 內容 |
|---|---|
| 系統管理員完整權限 | 勾選「系統管理員」後客戶端和內部端功能都能用；手冊 9.0 |
| 已知問題修正 | 只有專案管理員角色時不再 403；送測必須指定 Test；DEV／Test 開的單自動把負責人設成開單者；客戶編輯中不能派工；舊的客戶單通知連結會轉到 PM 頁；來源與角色顯示中文；`build.py` 不再漏掉 `config/services.php` |
| 協作功能（參考 Bugzilla） | 要求客戶補充資料、關注、重複單提示、我的查詢、內部單標籤、相依關係 |
| 客戶需求三項 | 客戶看得到處理進度；PM 看得到客戶結案（範圍篩選，結案時通知 PM）；一個更新包可以放多張單 |
| 更新包送測 | 套入更新包就送測，並跳出指定測試人員視窗（整批要嘛全部成功、要嘛全部不變） |
| 撤回送測 | 從更新包移出「待測試」的單會退回開發中並通知測試人員；已開始測試的單不能移出 |
| 會議 | 篩選類型、狀態、日期；匯出 Excel（全部或單筆，有會議、會議詳細、討論事項三張工作表，可見範圍和畫面一致） |
| 手冊 | 補上上述新功能的章節和截圖，已上線 |

本輪新增的升級 SQL：`upgrade-20261126-0431.sql`（協作功能的表等）、`upgrade-20261126-0559.sql`（撤回送測、會議匯出欄位），兩份都已在正式站執行並確認。

---

## 11. 待辦與建議

**待辦**
1. 確認 cPanel cron（每 5 分鐘）已設定且有在跑：看 `outbox` 是否從 pending 變成 sent，或看 log。
2. 確認郵件設定已填寫，「寄送測試郵件」成功；cPanel 的 Email Deliverability（SPF、DKIM）是綠色。
3. 重新產生離線版手冊 `docs/manual/dist`（manual.html 和 PDF，目前是舊版）。
4. 把部署和檢查用的一次性腳本範本移到 `deploy/tools/`。
5. `HANDOVER.md` 補上協作功能、撤回送測、會議匯出的說明。
6. 第一次升級 SQL 誤跑的那個資料庫，裡面多了空表和紀錄，由使用者決定是否清除；AI 不能產生刪除用的語法。

**功能建議**
- 會議通知：指派負責人、改時間、事項快到期時通知與會者。
- 「我的待辦」整合頁：把需要我處理的客戶單、內部單、會議事項放在同一頁。
- 每週摘要信，取代逐筆通知，避免信太多。
- 有附件的匯出：目前匯出的是附件連結，不是附件本身。
