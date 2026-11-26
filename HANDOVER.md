# CITS 開發交接文件

> 客戶問題追蹤系統（Customer Issue Tracking System）
> 交接日期：2026-11-24（2026-11-25 更新）　版本：P1 上線、P2 完成待部署
> 正式站：https://example.com/mescits/

---

## 0. 一分鐘現況

| 項目 | 狀態 |
|---|---|
| 程式碼 | P1 功能完成，自動化測試 73/73 通過 |
| 正式站程式 | 已部署，首頁 `/mescits/` 直接顯示登入畫面 |
| 正式站資料庫 | 已建立（install.sql 已匯入）。P2 需要先執行 `deploy/out/upgrade-20261124-1635.sql` |
| `.env` 資料庫密碼 | 已填 |
| 主機 PHP 擴充 | **必須用 `nd_pdo_mysql`**（CloudLinux PHP Selector）。預設的 `pdo_mysql` 會讀回錯位資料，見第 12 節 |
| cron | 待使用者確認 |
| git | repo 已存在（分支 master），**沒有任何 commit** |
| admin 密碼 | `ADMIN_PASSWORD.local.txt`（8 位數字，已在 .gitignore 裡） |

---

## 1. 技術棧

| 層 | 技術 |
|---|---|
| 語言／框架 | PHP 8.5、Laravel 13.43 |
| 資料庫 | 正式 MariaDB 11.4.10；本機與測試用 SQLite |
| 前端 | Blade + Alpine.js + Tailwind 4（Vite）。**沒有用 Livewire** |
| 權限 | spatie/laravel-permission，開啟 teams 模式（team = project_id） |
| MCP | laravel/mcp ^1.2（唯讀，`POST /mcp`，個人 API Token） |
| 編輯器 | TipTap（`cits/resources/js/editor/index.js`） |
| HTML 過濾 | symfony/html-sanitizer，存檔前在伺服器端過濾 |
| 測試 | Pest 4，SQLite in-memory |
| 主機 | iFastNet 付費共享主機，cPanel，LiteSpeed，PHP 8.5.6 |

---

## 2. 目錄結構

```
Customer_Issue_Tracking_System/
├─ AGENTS.md / PROJECT_CONTEXT.md / SESSION_LOG.md   AI 規則與專案記憶（僅 ASCII）
├─ HANDOVER.md                                       本文件
├─ ADMIN_PASSWORD.local.txt                          admin 初始密碼（不進 git）
├─ .histories/                                       每次修改的紀錄
├─ .claude/launch.json                               本機預覽（php artisan serve :8000）
├─ deploy/
│  ├─ README.md            部署步驟（給使用者）
│  ├─ build.py             打包：npm build → stage → composer --no-dev → app.zip + public/ + 解壓腳本
│  ├─ make_install_sql.php 用 migration 產生 MariaDB DDL，加上 seed 資料，輸出 out/install.sql
│  ├─ mcp_call.py          呼叫本機 iFastNET MCP 的小工具
│  └─ out/                 打包輸出（不進 git）
└─ cits/                   Laravel 本體
   ├─ docs/SPEC.md         給 AI 和開發者的規格摘要（必讀）
   ├─ app/
   │  ├─ Http/Controllers/<Module>/   Controller：只驗證輸入、呼叫 Service
   │  ├─ Http/Middleware/             https / active / project / admin
   │  ├─ Services/                    BLL：只寫商業規則，不碰資料庫
   │  ├─ Repositories/                DAL：所有 Eloquent / DB 操作都在這裡
   │  ├─ Models/                      只放 relation 和 cast
   │  ├─ Support/                     CurrentProject、helpers（tw()）
   │  ├─ Exceptions/BusinessException.php
   │  └─ Console/Commands/            cits:send-outbox、cits:cleanup-temp
   ├─ database/
   │  ├─ migrations/2026_11_24_000100_create_cits_tables.php   所有業務資料表
   │  └─ seeders/data/workflow.php    狀態、流轉、欄位規則、選項、角色、系統設定（資料驅動的核心）
   ├─ routes/web.php + routes/modules/*.php   模組路由用 glob 自動載入
   └─ tests/Feature/*.php
```

---

## 3. 架構硬規則（違反就是 bug）

1. **分層**：Controller → Service（BLL）→ Repository（DAL）。
   - Service 裡不准出現 `DB::`、`Model::where`、`->save()`。
   - 要用 transaction 就呼叫 `UnitOfWork::run(fn)`。
2. **不寫死業務值**：狀態、流轉、權限、選項、上限都從資料表讀：
   - `workflow_statuses`、`workflow_transitions`、`field_edit_rules`、`lookup_items`、`system_settings`、`project_settings`。
   - 可以讀狀態旗標（`is_terminal`、`is_excluded`、`is_released`、`client_tab`、`stage`）。
   - 不可以拿狀態代碼字串做 if 判斷。
3. **業務資料不刪除**：客戶單、內部單、留言都沒有刪除路由，「刪除」一律是改成取消狀態。
   - 唯一例外：沒有任何操作紀錄的使用者可以實體刪除，見 `AdminService::deleteUser`。
4. **時間**：資料庫一律存 UTC（連線 timezone `+00:00`），顯示用 `tw($dt)` 轉成 Asia/Taipei。
5. **中文**：程式碼中的 UI 字串直接寫中文字，不用 `\u` 轉義。
6. **AI 對正式資料庫只能 SELECT**：schema 變更一律產生 SQL 檔，由使用者自己執行。

---

## 4. 資料模型（41 張表）

**Laravel 內建**：users（有擴充欄位）、password_reset_tokens、sessions、cache、cache_locks、jobs、job_batches、failed_jobs。另有 spatie 權限相關的 5 張表。

| 分類 | 資料表 | 說明 |
|---|---|---|
| 專案 | projects、project_members、project_settings | 專案、成員、專案層級設定 |
| 設定 | system_settings、lookup_items | 全域設定、下拉選項。lookup 也放 issue_initial、ticket_initial、issue_create_perm 等對應關係 |
| 流程 | workflow_statuses、workflow_transitions、field_edit_rules | 資料驅動的狀態機 |
| 客戶單 | client_tickets、ticket_revisions、ticket_reopens、ticket_comments | 客戶溝通管道 |
| 內部單 | issues、issue_addenda、issue_comments、due_change_requests、packages | 內部溝通管道 |
| 關聯 | ticket_issue_links | 客戶單與內部單 N:M |
| 其他 | mes_pages、project_counters、events、comment_reads、attachments、upload_sessions、editor_drafts、notification_outbox、user_notifications | |

**重要欄位**：
- `client_tickets.dispatched_at`：連動鎖時間點，**設定後永不清除**。
- `client_tickets.needs_pm_action`：所有關聯內部單都被排除時設為 1，要 PM 處置。
- `client_tickets.row_version`：樂觀鎖版本號。
- `client_tickets.current_rev`：目前的修訂版號。
- `issues.rework_count`：退回重做的次數。
- `events`：統一的時間軸與稽核紀錄，`EventRepository::log` 寫入。

**編號格式**：由 `NumberingService` 產生，用 `project_counters` 加 `FOR UPDATE` 取號。
- 客戶單：`{ticket_prefix}-2026-0001`
- 內部單：`{ticket_prefix}-I2026-0001`

---

## 5. 流程（資料來源：`seeders/data/workflow.php`）

### 5.1 客戶單（ticket）

```
submitted ──ticket.accept──▶ in_progress ──system|ticket.settle──▶ pending_close ──ticket.close──▶ closed
    │                            │                                     │
    └─ticket.cancel─▶ cancelled ◀┘ ticket.cancel|ticket.settle         └─ticket.reopen─▶ in_progress
```

- `permission` 欄位用 `a|b` 表示「有其中一個權限就可以」；`system` 表示只能由系統觸發。
- `in_progress` 改成 `cancelled` 時必須填 `cancel_reason`。

### 5.2 內部單（issue）

```
new / discussing ──issue.dispatch(assignee_dev_id)──▶ dev_todo ──dev.manage(planned_due)──▶ dev_doing ──dev.manage(package_id, assignee_test_id)──▶ test_pending
                 └─issue.dispatch(assignee_test_id)──▶ test_pending
test_found ─────────dev.manage──▶ dev_doing          （Test 新發現的問題直接進 DEV）
test_pending ──test.manage──▶ test_doing / test_scheduled ──▶ test_doing
test_doing ──▶ test_failed ──dev.manage──▶ dev_doing（rework）
test_doing ──test.manage(release_planned_at)──▶ release_pending ──release.manage──▶ released
new / discussing / dev_todo / test_found ──issue.cancel(cancel_reason)──▶ cancelled / wont_fix
```

- 各流轉的必填欄位：`dev_todo` 要 `assignee_dev_id`，`dev_doing` 要 `planned_due`，`dev_doing → test_pending` 要 `package_id`、`assignee_test_id`（單上已有 Test 負責人時沿用，不再詢問），`new/discussing → test_pending` 要 `assignee_test_id`。
- 每個流轉都在 `IssueService::transition` → `WorkflowService::assertTransition` 檢查權限和必填欄位。

### 5.3 結算規則（`SettlementService::recompute`）

內部單每次變更狀態時，重新計算所有關聯的客戶單：
- 有效內部單 = 關聯內部單中 `is_excluded=0` 的那些。
- 有效內部單不為空，而且全部 `is_released` → 客戶單轉成 `pending_close`。
- 有效內部單為空（全部被取消或不處理）→ `needs_pm_action=1`。PM 要在 `/pm/tickets/{id}/settle` 處置，三選一：轉待結案、取消客戶單、開新內部單。

### 5.4 連動鎖

- 任一關聯內部單被派工到 DEV 或 TEST → 所有關聯客戶單設定 `dispatched_at`。
- 把客戶單連結到已派工的內部單，也會設定 `dispatched_at`。
- 設定之後，客戶不能編輯、修訂、取消，但仍然可以留補充留言。
- 派工時依固定順序鎖定：先依 id 排序 `FOR UPDATE` 所有客戶單，再鎖內部單。任一張客戶單有別人有效的編輯鎖就拒絕派工。

### 5.5 編輯鎖

- `TicketService::startEdit` 取得鎖，前端每隔一段時間呼叫 `heartbeat`，離開時呼叫 `releaseEdit`。
- TTL 由 `ticket.edit_lock_minutes`（5 分鐘）控制。
- 存檔時比對 `row_version`，不一致就拒絕，避免覆蓋別人的修改。
- 客戶修訂後立即生效，寫入 `ticket_revisions` 並通知 PM，PM 用 `ack` 確認已讀。

### 5.6 欄位編輯權（`field_edit_rules`）

| origin | 欄位 | stage | 權限 | actor_rule |
|---|---|---|---|---|
| test | severity | 任何 | test.manage | **creator**（只有開單的 Test 本人可改） |
| client/pm | severity | PM | issue.edit_pm | any |
| dev | severity | DEV | dev.manage | creator |
| * | title, content_html, process_type, mes_page_id | PM | issue.edit_pm | any |
| * | difficulty, planned_start, dev_note | DEV | dev.manage | any |

---

## 6. 角色與權限

角色是全域定義，但指派到專案（一個人在同一個專案可以有多個角色）。admin 透過 `Gate::before` 擁有所有權限。

| 角色 | 類型 | 權限 |
|---|---|---|
| client_pm | client | ticket.view/create/edit/cancel/close/reopen/comment |
| client_view | client | ticket.view（只能看） |
| internal_pm | internal | ticket.view/accept/reply/settle、issue.view/create/edit_pm/dispatch/cancel/link、due.approve、mes.manage |
| dev | internal | ticket.view、issue.view/create、dev.manage、due.request、mes.manage |
| test | internal | ticket.view、issue.view/create、test.manage、release.manage、mes.manage |

`CurrentProject` 在每個 request 都會呼叫 `setPermissionsTeamId($projectId)`，所以 `$user->can('ticket.create')` 會依目前選的專案判斷。

---

## 7. 頁面與路由（共 79 條）

| 模組 | 路由 | Controller | 說明 |
|---|---|---|---|
| 登入 | `GET /` | Auth\HomeController | 沒登入顯示登入畫面；已登入依身分轉到 `/tickets` 或 `/pm` |
| | `GET /login` | closure | 轉到首頁（給舊連結用） |
| | `POST /login`、`POST /logout` | Auth\LoginController | |
| | `/projects/select` | Auth\ProjectSelectController | 只屬於一個專案時自動選定 |
| | `/account/password` | Account\PasswordController | |
| 系統管理 | `/admin/users`、`/admin/projects`、`/admin/projects/{p}/members` | Admin\* | 帳號、專案、成員與角色 |
| 客戶 | `/tickets`（4 個 tab）、`/tickets/create`、`/tickets/{id}`、`edit`、`heartbeat`、`release`、`cancel`、`close`、`reopen`、`comment` | Client\TicketController | |
| PM | `/pm`、`/pm/tickets/{id}`（accept/ack/issue/link/quote/reply/settle）、`/pm/issues/create`、`/pm/due/{id}/review` | Pm\* | |
| 內部單 | `/issues/{id}`（transition/fields/due/due-request/comment/addendum） | Issue\IssueController | DEV、Test、PM 共用的詳細頁 |
| DEV | `/dev`、`/dev/issues/create`、`/dev/packages` | Dev\DevController | |
| Test | `/test`、`/test/issues/create` | Test\TestController | |
| 時序 | `/timeline` | Timeline\TimelineController | 取代 SLA，用日期排序 |
| MES | `/mes`、`/mes/import/preview`、`/mes/import/apply`、`/mes/{id}/toggle` | Mes\MesController | |
| 上傳 | `/uploads/image`、`/uploads/init`、`/uploads/{id}/chunk`、`/uploads/{id}/complete`、`/attachments/{id}`、`/drafts` | Upload\* | 5MB 分段，最大 100MB |

---

## 8. 核心 Service API

| Service | 主要方法 |
|---|---|
| WorkflowService | statusMap, status, label, codesForTab, codesForStage, initial, lookups, setting, can, authorize, allowedTargets, targetByPermission, assertTransition, canEditField |
| TicketService | abilities, timeline, create, startEdit, heartbeat, releaseEdit, saveEdit, cancel, addComment, markRead, close, reopen, accept, ackRevisions, settle |
| IssueService | abilities, timeline, createFromTickets, createInternal, updateFields, setInitialDue, transition, link, requestDueChange, reviewDueChange, createPackage, packages, addComment, addAddendum, quoteComment |
| SettlementService | recompute |
| NumberingService | 客戶單與內部單編號 |
| ContentService / SanitizerService | 編輯器 HTML 過濾、處理暫存圖片 |
| AttachmentService / DraftService | 附件、草稿 |
| OutboxService | sendBatch, applySmtpConfig（從 system_settings `mail.*` 讀 SMTP 設定） |
| AuthService / AdminService | 登入節流、帳號與專案管理 |
| Client/Pm/Dev/Test/Timeline/Mes 的 *Service | 各頁面的查詢組裝 |

錯誤處理：Service 拋出 `BusinessException`，訊息是給使用者看的中文；Controller 接住後 `back()->with('err', ...)`。

---

## 9. 系統設定（`system_settings`）

| key | 預設 | 用途 |
|---|---|---|
| mail.enabled | 0 | 是否寄信。0 時 outbox 不送 |
| password.min_length | 6 | 密碼最短長度，不要求複雜度 |
| login.max_attempts | 5 | 登入失敗上限 |
| login.decay_minutes | 15 | 登入鎖定時間 |
| ticket.edit_lock_minutes | 5 | 編輯鎖 TTL |
| attachment.max_mb | 100 | 附件大小上限 |
| attachment.allowed_ext | (清單) | 允許的副檔名 |

`OutboxService` 會讀 `mail.host/port/encryption/username/password/from_address/from_name/max_attempts`，但**目前沒有 UI 可以設定這些值**。

---

## 10. 本機開發

```powershell
# PHP 8.5 NTS 用 winget 安裝；Composer 在 %LOCALAPPDATA%\cits-tools
cd D:\Project\Customer_Issue_Tracking_System\cits
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSeeder   # 示範資料：cpm1 / cview1 / pm1 / dev1 / test1，密碼都是 123456
npm run dev
php artisan serve --port=8000
php artisan test                         # 73 tests
vendor/bin/pint                          # 程式碼格式化
```

- 在 Git Bash 裡先執行 `. /tmp/env.sh`（設定 PATH 並 cd 到 cits）。這個檔案重開機後會消失，內容只有兩行，見 `PROJECT_CONTEXT.md` 的 Build/Deploy 段落。
- 本機的 `.env` 用 SQLite。正式環境的設定由 `build.py` 的 `env_template()` 產生。

---

## 11. 部署

### 11.1 主機路徑

| 用途 | 主機路徑 | WebDAV profile | 是否公開 |
|---|---|---|---|
| Laravel 本體（含 .env、vendor、storage） | `/home/cpuser/app/mescits` | webdav-app | 否（已驗證 404） |
| 公開檔案（index.php、build/、.htaccess） | `/home/cpuser/public_html/mescits` | webdav-demo | 是 |

- `public/index.php` 改過三個路徑，指向 `__DIR__.'/../../app/mescits/...'`，並加上 `$app->usePublicPath(__DIR__)`，少了這行會出現 Vite manifest 找不到的 500 錯誤。
- `.htaccess` 加了強制轉 HTTPS 的規則和 `Options -Indexes`。

### 11.2 完整重新部署

```powershell
cd D:\Project\Customer_Issue_Tracking_System
php deploy\make_install_sql.php   # 只有新建資料庫時需要
php deploy\make_upgrade_sql.php   # 既有資料庫：產生增量 SQL（新 migration + INSERT IGNORE lookup/設定）
python deploy\build.py
```

1. 把 `deploy/out/app.zip` 上傳到 webdav-app 的 `/`。
2. 把 `deploy/out/x_*.php` 上傳到 webdav-demo 的 `/`。
3. 開啟 `https://example.com/mescits/x_xxx.php?t=<token>`（token 在 `extractor.txt`），腳本會：
   - 解壓 zip。
   - 主機上已有 `.env` 就保留不動。
   - 不覆蓋 storage。
   - 清掉 bootstrap/cache 裡的快取檔。
   - 最後刪除 zip 和自己。
4. 把 `deploy/out/public/*` 上傳到 webdav-demo 的 `/`。


### 11.3 小改動（只改 PHP 或 Blade）

沒有 route cache 或 config cache，可以直接用 MCP `upload_items` 覆寫單一檔案：

```python
call('upload_items', {'profile': 'webdav-app', 'paths': [abs_path],
                      'remoteDirectory': '/app/Http/Controllers/Auth', 'overwrite': True})
```

- 改到前端（js/css）就要跑 `npm run build`，再上傳 `public/build/`。
- 改到 Blade 也不用清 view cache：Laravel 會比對檔案時間，自動重新編譯。

### 11.4 Schema 變更

正式資料庫不能用 `migrate`，因為 AI 不能動正式資料庫，而且主機也沒有 SSH。請照這個流程：
1. 寫新的 migration。
2. 用 pretend 模式產生 ALTER SQL（可參考 `make_install_sql.php` 的做法）。
3. 把 SQL 交給使用者在 phpMyAdmin 執行。
4. 用 MCP 下 SELECT 驗證。
5. 在 `migrations` 表補上該筆紀錄，SQL 檔裡要包含這筆 INSERT。

### 11.5 Cron（cPanel）

```
* * * * * /usr/local/bin/php /home/cpuser/app/mescits/artisan schedule:run >/dev/null 2>&1
```

排程定義在 `cits/routes/console.php`：
- `cits:send-outbox`：每分鐘，withoutOverlapping。
- `queue:work --stop-when-empty --max-time=50`：每分鐘。
- `cits:cleanup-temp`：每天。

**未驗證**：cron 用的命令列 PHP 版本。如果不是 8.5，路徑改成 `/opt/cpanel/ea-php85/root/usr/bin/php`，這也是推測的路徑。

---

## 12. 主機與資料庫的已知事實和雷區

- **主機 PDO 擴充（最重要）**：CloudLinux alt-php85 預設的 `pdo_mysql.so` 連結 libmariadb 10.5.5，跟 PHP 8.5 結構不相容：native prepares 讀回錯位資料、emulated prepares 全部變字串、欄位 metadata 也錯。**一定要在 cPanel「Select PHP Version」→ Extensions 改用 `nd_pdo_mysql`**。症狀：正確密碼登入失敗、單子「找不到」、欄位空白。檢查方式：`PDO::ATTR_CLIENT_VERSION` 必須以 `mysqlnd` 開頭。
- **子路徑部署**：網站跑在 `/mescits` 底下。`Route::redirect('/x', '/')`、`redirectGuestsTo('/')` 這種用字串寫的轉址會跳到網域根目錄，**一定要用 `route()` 或 closure**。這個問題已經在正式站踩過。
- **Collation 一定要用 `utf8mb4_uca1400_ai_ci`**：`unicode_ci` 和 `general_ci` 會把不同的 CJK 擴充 B 區罕用字判定為相等。也不要用 utf8mb3，罕用字會變成 `?`。
- **連線逾時**：MariaDB 的 `wait_timeout=60s`，長時間的工作（組合分段上傳、寄信）不能讓連線閒置超過 60 秒。
- **網頁執行時間**：web 的 `max_execution_time=60`，100MB 檔案才必須分段上傳。
- **沒有 sodium 擴充**：不要用依賴 sodium 的功能。
- **中文排序與搜尋**：`ORDER BY` 中文是照 code point 排，不是筆畫或注音。沒有 ngram，中文搜尋只能用 LIKE。
- **MCP 的中文顯示**：`execute_maria_db_sql` 回傳的部分中文會亂碼，這是 MCP 端解碼的問題。用 `HEX()` 查驗，實際存的 bytes 是正確的。
- **MCP 權限**：`execute_maria_db_sql` 其實可以執行 DDL，但依規則只能下 SELECT。建議幫 MCP 另外建一個唯讀的資料庫帳號。
- **沒有刪檔工具**：本機 MCP 沒有 delete tool，主機上的檔案要刪就請使用者用 cPanel 刪，或用會自我刪除的腳本。

---

## 13. 已知問題與技術債（依優先順序）

### P0：上線前要確認
1. ~~`/admin` 轉址~~：已修（2026-11-24），編輯器 API 路徑也改用 `meta app-base`。
2. `redirect('/x')` 與 `url('/x')` 會自動加上子路徑，已在正式站驗證可用；不可用的是 `Route::redirect` 與字串形式的 `redirectGuestsTo`。
3. 完整驗證流程：
   - 用 admin 登入。
   - 建專案、建使用者、指派角色。
   - 跑一次完整流程：客戶開單 → PM 接收並開內部單 → DEV → Test → 上線 → 客戶結案。

### P1：寫死業務值（2026-11-25 已處理）
- 改用 lookup 分類：`client_tab`、`category_required_type`、`comment_kind_internal`、`settle_tab`、`test_bounced_status`、`issue_origin`、`issue_origin_external`、`stage_role`。
- 讀取方法：`WorkflowService::lookupCodes / lookupMap / lookupValue`。新增分類時要寫進 `workflow.php`，再用 `make_upgrade_sql.php` 帶到正式站。

### 決定紀錄
- 客戶時間軸維持顯示內部人員真名（使用者決定 2026-11-25）。

### 其他
- `ticket_revisions` 只存標題與內容，類型、類別、MES 頁面沒有版本化；修訂比對頁顯示的是目前值。
- MCP 的 429（限流）路徑沒有測試。
- 測試只跑 SQLite，**沒有在 MariaDB 上跑過**。`FOR UPDATE`、collation、strict mode 這類差異只在正式環境才會遇到。建議本機架一個 MariaDB 11.4 跑一次完整測試。

---

## 14. P2 功能（2026-11-25 完成）

| 功能 | 入口 | 說明 |
|---|---|---|
| SMTP 設定與測試寄信 | `/admin/settings/mail`（admin） | 密碼用 APP_KEY 加密、不回顯；加密方式 none/tls/ssl |
| 站內通知 | 頂部鈴鐺、`/notifications` | 未讀數、標示已讀、全部已讀 |
| 忘記密碼 | 登入頁「忘記密碼？」 | 需啟用郵件；中性回應防帳號探測；token 60 分鐘 |
| 個人設定 | `/account/settings` | Email 通知開關（預設開） |
| 報表 | `/reports`、`/reports/export` | 狀態、處理天數、逾期、DEV 退回、6 個月趨勢；CSV（BOM、防公式注入） |
| MCP | `POST /mcp`、`/account/tokens` | Bearer `cits_...`；list_projects / list_issues / get_issue / list_tickets；只讀 |
| Web.sitemap 匯入 | `/mes` | 先預覽，再套用；拒絕 DOCTYPE/ENTITY；不停用缺漏頁 |
| 修訂版比對 | 修訂紀錄的「與前一版比較」 | 行層級差異、輸出一律跳脫 |

**沒做**：laravel/boost（`cits/CLAUDE.md` 是框架自帶的檔案，要求安裝；**刻意沒裝**，因為會改 composer.json 和 AGENTS.md）。

---

## 15. 測試覆蓋

| 檔案 | 測試數 | 涵蓋內容 |
|---|---|---|
| AuthTest | 16 | 帳號或 Email 登入、停用帳號、登入節流、密碼長度、HTTPS 轉址與 HSTS、secure cookie、自動選專案 |
| AdminTest | 11 | 帳號、專案、成員與角色管理 |
| CoreFlowTest | 14 | 連動鎖、結算、排除與 needs_pm_action、編輯鎖、樂觀鎖、新發現嚴重度只限開單者、reopen |
| ClientPagesTest | 7 | 客戶頁 tab、權限、View 角色唯讀 |
| PmPagesTest | 7 | PM 頁面 |
| DevTestPagesTest | 7 | DEV 與 Test 頁面 |
| UploadTest | 9 | 分段上傳、圖片、草稿、HTML 過濾 |
| ExampleTest | 1 | 首頁顯示登入畫面 |

---

## 16. 協作規則（摘自 AGENTS.md，接手者必看）

- 開始工作前，先讀 `PROJECT_CONTEXT.md` 和 `SESSION_LOG.md`。
- 有重要修改，就更新 `SESSION_LOG.md`；使用者指定修改某個檔案時，要在 `.histories/<filename>-<timestamp>.md` 記錄。
- 不可以用 `git add .` 或 `git add -A`，也不可以 `reset`、`checkout --`、`clean`。commit 和 push 都要使用者明確同意。
- `AGENTS.md`、`PROJECT_CONTEXT.md`、`SESSION_LOG.md` 只能用 ASCII。
- 不可以在對話、log 或文件裡寫出密碼、token、hash。
