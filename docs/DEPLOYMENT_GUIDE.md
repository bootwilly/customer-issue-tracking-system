# CITS 部署手冊（現況版）

> 版本：2026-11-27。正式站已套用到 migration `2026_12_02_000100_widen_attachment_types`（共 16 個），程式碼和正式站同步。
> 本手冊說明：平台需求、系統特性、**全新安裝**、**資料庫升級**、**程式部署**、手冊部署、draw.io 部署、上線後設定、驗證、出問題時怎麼處理，以及每一步的注意事項。
> 開發相關的背景請看 `docs/PLATFORM_DEV_RECORD.md`；長期規則請看 `PROJECT_CONTEXT.md`。

---

## 0. 重要原則（每次都要遵守）

1. **AI 不直接修改正式站資料庫。** 資料表變更一律產生 SQL 檔，由使用者在 phpMyAdmin 執行；AI 只用唯讀的 SELECT／SHOW 檢查結果。
2. **先跑 SQL，再上程式。** 新程式依賴新的表或設定，順序相反會讓網站出錯；舊程式不會讀新的表，所以先跑 SQL 是安全的。
3. **用凍結快照部署。** 先把整份程式複製成快照，在快照裡建置、測試、打包，上傳的就是測過的那份。工作目錄在部署期間被別人修改也不會影響。
4. **上傳順序：新的前端檔 → app.zip → 解壓 → manifest 最後。** manifest 一換，瀏覽器就會改用新的前端檔，所以新的前端檔必須先在。
5. **每次部署都要驗證**：md5、前端檔、三種身分的登入測試、錯誤 log。
6. 一次性腳本（解壓、檢查、測試）一律**帶 token、執行一次就自刪**，跑完要確認再開是 404。

---

## 1. 平台需求

### 1.1 主機（正式站現況）
| 項目 | 需求／現況 |
|---|---|
| 主機類型 | cPanel 共享主機（iFastNet），LiteSpeed（相容 Apache `.htaccess`） |
| SSH | 不需要（正式站沒有）。部署全部透過 WebDAV 上傳加上一次性 PHP 腳本 |
| PHP | **8.5**（網頁和 CLI 一致）。CLI 路徑 `/usr/local/bin/php` |
| PHP 設定 | upload_max_filesize ≥ 512M、post_max_size ≥ 800M、memory_limit ≥ 512M、max_input_time 300。zip（ZipArchive）、mbstring、intl、gd、fileinfo、openssl 都要有。sodium 可以沒有 |
| **PDO 驅動** | **必須使用 `nd_pdo_mysql`（mysqlnd）**：cPanel → Select PHP Version → Extensions。預設的 `pdo_mysql` 和 PHP 8.5 不相容，會讀出錯亂的資料，例如所有值都變成字串、欄位錯位 |
| 資料庫 | MariaDB 11.4，字元集 utf8mb4，定序 `utf8mb4_uca1400_ai_ci` |
| sql_mode | 正式站是 `''`（非嚴格）。varchar 超長會被**直接截斷不報錯**，所以程式和種子資料都要自己控制長度 |
| DB 連線上限 | max_user_connections = 38 |
| cron | 需要。正式站設定為每 5 分鐘一次 |
| 對外連線 | 需要可以連 SMTP（465 或 587）；以後做通知頻道時，還要能連 Discord、Telegram、LINE、Microsoft 的 HTTPS |
| HTTPS | 需要。`.htaccess` 會強制轉 HTTPS，Session cookie 也設成只走 HTTPS |
| 磁碟 | app 約 70 MB，加上附件和 log |

### 1.2 目錄配置（安全關鍵）
| 位置 | 內容 | WebDAV profile |
|---|---|---|
| `/home/cpuser/public_html/mescits` | **只放** Laravel `public/` 的內容：`index.php`、`.htaccess`、`build/`、`drawio/`、favicon、robots | `webdav-demo` |
| `/home/cpuser/app/mescits` | Laravel 核心（app、vendor、config、storage、`.env`、resources/manual） | `webdav-app` |

- **絕對不可以把 Laravel 根目錄放進 public_html**：`.env`、`storage`、`vendor` 會被公開下載。
- 公開目錄的 `index.php` 已改成載入 `__DIR__.'/../../app/mescits/...'`，由 `build.py` 自動修改並檢查。
- 附件存在 `storage`，位於 public_html 之外。

### 1.3 開發／部署用電腦（Windows）
- PHP 8.5 NTS（winget）：`C:\Users\<you>\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.NTS.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`
- Composer：`%LOCALAPPDATA%\cits-tools\composer.phar`
- Node.js 和 npm（Vite 建置）、Python 3（`deploy/build.py`、`deploy/mcp_call.py`）。
- `deploy/mcp_call.py` 用 WebDAV 上傳、下載、列出遠端檔案，連線設定是 `webdav-demo` 和 `webdav-app` 兩個 profile。
- 用 PowerShell 的 robocopy 建快照，用 Git Bash 跑 md5sum、curl。

---

## 2. 系統特性（部署時要知道的）

- **時區**：程式和資料庫都存 UTC，畫面顯示台北時間。正式站 log 的時間也是 UTC。
- **設定都放在資料庫**：狀態、轉換、選項、匯出欄位、權限都在 `workflow_statuses`、`workflow_transitions`、`lookup_items`、`system_settings`、`permissions`、`role_has_permissions`。
  - 來源是 `cits/database/seeders/data/workflow.php`。
  - 升級時用 INSERT IGNORE 補上新資料，**不會覆寫**管理員在畫面上改過的名稱或排序。
  - 要修改已存在的資料，只能另寫 migration。
- **快取**：
  - spatie 的權限快取：新增權限或角色後要清。
  - `cits.workflow_status_map`：狀態表有變動時要清，快取 300 秒。
  - Session、cache 都用檔案（storage/framework）。
- **Email**：所有通知先寫進 `outbox`，由排程寄出。SMTP 設定在 系統管理 → 郵件設定，存在 system_settings，密碼加密。`.env` 的 `MAIL_MAILER=log` 不用改。
- **背景工作**：`QUEUE_CONNECTION=database`。沒有常駐的 worker，由排程每次執行 `queue:work --stop-when-empty`。
- **手冊**：`docs/manual/*.md` 和 `img/` 在打包時放進 `resources/manual`，系統依角色過濾章節。
- **draw.io**：自架在 `public_html/mescits/drawio`，約 3000 個檔案。只在升級 draw.io 版本時才用 `--drawio` 部署。

---

## 3. 最終版 SQL

| 檔案 | 用途 |
|---|---|
| **`deploy/out/install-20261127.sql`** | **全新安裝用的最終版**：空的資料庫執行一次，就建好全部 16 個 migration 的表、索引、外鍵，以及全部種子資料（狀態 27、轉換 40、欄位規則 11、選項 239、系統設定 20、權限 36、角色 6、角色權限 72）和初始管理員帳號 |
| `deploy/out/install.sql` | 內容同上。`make_upgrade_sql.php` 會以它的 migration 清單當作「已套用」的基準 |
| `deploy/out/install-20261123.sql`、`install-20261126.sql` | 舊版（14 個 migration 以前），只留作紀錄，**不要再使用** |
| `deploy/out/upgrade-20261126-0431.sql`、`upgrade-20261126-0559.sql`、`upgrade-20261126-1633.sql`、`upgrade-20261126-1700.sql` | 正式站已執行過的升級檔，留作紀錄，**不要重跑**（1633 是登入紀錄 `auth_logs`，1700 是附件類型和依帳號的登入限制） |

**正式站現在不需要跑任何 SQL。** 以後有新的 migration 或種子資料時，照第 5 節產生新的升級 SQL。

### 3.1 install SQL 的注意事項
- 只能在**空的資料庫**執行，因為它用 `CREATE TABLE` 和一般的 `INSERT`，表或資料已存在就會失敗。
- 裡面有初始管理員帳號 `admin`，密碼是產生 SQL 時環境變數 `ADMIN_INITIAL_PASSWORD` 的值（SQL 裡存的是雜湊）。**沒有設這個變數就不會有管理員帳號，裝好後會沒辦法登入。** 第一次登入後請立即改密碼，產生出來的 SQL 不要外流，也不要放進 git（`deploy/out/` 已被忽略）。
- **這個檔案不在 repo 裡**，下載專案的人要照 3.2 自己產生，每個人產生的管理員密碼不同。
- 檔案開頭有 `SET FOREIGN_KEY_CHECKS = 0`，結尾設回 1。
- migration 裡的資料更新語法（例如把 `meeting_it` 改成 `meeting_item`、送測必填 Test）在空資料庫上會更新 0 筆，沒有影響；種子資料本來就是最新的值。

### 3.2 怎麼重新產生最終版 install SQL
```bash
# 用暫存的 SQLite，不要動到本機的開發資料庫（cits/database/database.sqlite）
cd D:/Project/Customer_Issue_Tracking_System
: > /tmp/install-final.sqlite
export DB_CONNECTION=sqlite DB_DATABASE=/tmp/install-final.sqlite
export ADMIN_INITIAL_PASSWORD='<自訂的管理員密碼>'   # 必填：沒設就不會建立 admin 帳號
# （原作者是從 git 忽略的 ADMIN_PASSWORD.local.txt 讀取：export ADMIN_INITIAL_PASSWORD="$(tr -d '\r\n' < ADMIN_PASSWORD.local.txt)"）
(cd cits && php artisan migrate --seed --force)
php deploy/make_install_sql.php          # 輸出到 deploy/out/install.sql
unset ADMIN_INITIAL_PASSWORD
cp deploy/out/install.sql deploy/out/install-$(date +%Y%m%d).sql
```
**不要**在本機的開發資料庫跑 `migrate:fresh`，那會清掉所有開發資料。

---

## 4. 全新安裝（新主機或新的資料庫）

> 只有在全新環境才用。正式站已經上線，**不要**對正式站做這一節。

1. **cPanel 準備**
   - Select PHP Version 選 8.5，Extensions **勾 `nd_pdo_mysql`、取消 `pdo_mysql`**。
   - MySQL Databases：建立資料庫和使用者（例如 `cpuser_mescits`），給這個使用者「全部權限」。
   - 建立 Web Disk（WebDAV）兩個帳號：一個指到 `public_html/mescits`，一個指到 `app/mescits`（要在 public_html 之外）。把連線資訊設定到 `deploy/mcp_call.py` 的 profile。
2. **資料庫**：phpMyAdmin → **左側先點選新建的資料庫** → SQL 分頁 → 貼上或匯入 `deploy/out/install-20261127.sql` → 執行。
   - 確認：`SELECT COUNT(*) FROM migrations;` 應該是 14；`SELECT COUNT(*) FROM lookup_items;` 應該是 214。
3. **建置**：照第 6 節的步驟 1 到 4 建立快照、建置、測試、打包。
4. **上傳公開檔**：把 `deploy/out/public/` 整個資料夾的內容（`index.php`、`.htaccess`、`build/`、favicon、robots）上傳到 `webdav-demo` 的 `/`。
5. **上傳核心並解壓**：照第 6 節的步驟 5-2、5-3。
   - 第一次部署時，`app/mescits/.env` 還不存在，解壓程式會把 `env.production` 範本裝成 `.env`，裡面的 APP_KEY 是隨機產生的。
6. **補上 `.env` 的資料庫密碼**：`DB_PASSWORD` 在範本裡是空的。用 WebDAV 下載 `app/mescits/.env`，填好再上傳；或是用 cPanel 的檔案管理員直接編輯。**不要讓 AI 經手密碼。**
   - 如果網域或子目錄不同，也要改 `APP_URL`；建置時的 `ASSET_URL` 也要對應修改。
7. **draw.io**（需要流程圖功能時）：在快照執行 `python -I deploy/build.py --no-npm --drawio`，上傳 `drawio.zip` 和 `xd_*.php` 到 `webdav-demo` 的 `/`，再呼叫 `extractor_drawio.txt` 裡寫的網址。
8. **上線後設定**：做第 9 節（管理員改密碼、郵件、cron、建立專案和成員）。
9. **驗證**：做第 7 節。

---

## 5. 資料庫升級（有新的 migration 或種子資料時）

1. **先確認正式站已套用到哪一個 migration**：看 `SESSION_LOG.md`，或用唯讀腳本查 `SELECT migration, batch FROM migrations ORDER BY id`（範本見附錄 B）。
2. **產生 SQL**：
   ```bash
   php deploy/make_upgrade_sql.php                                   # 用 deploy/out/install.sql 當基準
   php deploy/make_upgrade_sql.php --no-baseline --applied-file=applied.txt   # 最可靠：以正式站實際的清單為準
   ```
   - 程式需要知道「正式站已經套用了哪些 migration」，來源有三種，可以合併使用：
     1. `deploy/out/install.sql`（`make_install_sql.php` 產生）。**這個檔案不在 repo 裡**，只有自己產生過才有。
     2. `--applied-file=<檔案>`：在 phpMyAdmin 執行 `SELECT migration FROM migrations ORDER BY id;`，把結果匯出或複製成文字檔，一行一個名稱。程式會自動抓出 migration 名稱，多出來的標題或引號都沒關係。
     3. `--applied=a,b,...`：直接列名稱。
   - 三種都沒有的話，程式會停下來，並提示你去查 migrations 表。
   - 原作者的 `install.sql` 已更新成 2026-11-26 的最終版，和正式站一致，所以原作者目前直接執行就可以。
   - 輸出到 `deploy/out/upgrade-<日期時間>.sql`，內容包括：
     - 待套用 migration 的 DDL
     - 對應的 migration 紀錄
     - workflow 狀態、轉換、選項、系統設定、權限和角色權限的 INSERT IGNORE
   - **修改已存在的資料只能寫 migration**，因為 INSERT IGNORE 不會更新已存在的列。資料 migration 要用 `DB::statement` 寫原生 SQL，否則 `SeedDataLengthTest` 和產生 SQL 的程式（都用 pretend 模式）會失敗。
3. **先自己看一遍 SQL**：確認沒有預期外的 DROP 或 DELETE，例如某個 migration 的 down 被誤放進 up。
4. **交給使用者執行**：
   - phpMyAdmin → 先「匯出」做備份 → **左側點選 `cpuser_mescits`**（曾經誤跑到別的資料庫）→ SQL 分頁 → 執行。
   - 確認畫面最後一行是「已執行 N 個查詢」，沒有紅色錯誤。
   - 中途出錯時，phpMyAdmin 會停在那一行，前面已執行的 DDL **不會回復**。把錯誤訊息記下來，針對剩下的部分重新產生 SQL，**不要整份重跑**。
5. **用唯讀腳本確認**（附錄 B）：新的表在、migration 紀錄在、新的選項和權限在。
6. 確認沒問題後，才做第 6 節的程式部署。

---

## 6. 程式部署（凍結快照流程）

> 範例路徑：`SP` = 一個暫存資料夾，快照命名 `snap-YYYYMMDDx`。PowerShell 和 Git Bash 都會用到。

### 步驟 1：建立快照（PowerShell）
```powershell
$S = "$SP\snap-20261201a"
$R = 'D:\Project\Customer_Issue_Tracking_System'
robocopy "$R\cits" "$S\cits" /E /NFL /NDL /NJH /NP /XD "$R\cits\vendor" "$R\cits\node_modules" "$R\cits\storage" "$R\cits\.git" "$R\cits\public\drawio" "$R\cits\public\build"
robocopy "$R\docs\manual" "$S\docs\manual" /E /NFL /NDL /NJH /NP /XD "$R\docs\manual\dist"
New-Item -ItemType Directory -Force "$S\deploy" | Out-Null
Copy-Item "$R\deploy\build.py" "$S\deploy\build.py"
robocopy "$R\cits\vendor" "$S\cits\vendor" /E /NFL /NDL /NJH /NP /MT:16      # vendor 要實際複製
New-Item -ItemType Junction -Path "$S\cits\node_modules" -Target "$R\cits\node_modules" | Out-Null
foreach ($d in 'app\private','app\public','framework\cache\data','framework\sessions','framework\views','framework\testing','logs') { New-Item -ItemType Directory -Force "$S\cits\storage\$d" | Out-Null }
```
- robocopy 結束代碼 1 到 7 都算成功（PowerShell 會顯示 Exit code 1，不用理它）。
- **vendor 不能用 junction**：Pest 會把測試對應到原本的資料夾，出現像「Call to undefined method …::get()」這類奇怪的錯誤。
- **手冊**：如果有人正在改 `docs/manual`（看檔案修改時間），先確認他寫完沒；沒寫完就把上一次部署快照裡的 `docs\manual` 用 `robocopy /MIR` 蓋回來，這次只部署程式。

### 步驟 2：記錄 md5 基準，建置前端，跑測試（Git Bash）
```bash
cd "$S" && find cits docs deploy -type f -not -path "*/vendor/*" -not -path "*/node_modules/*" -print0 | sort -z | xargs -0 md5sum > ../snap.md5
cd cits && ASSET_URL=/mescits npm run build          # Windows 上是 npm.cmd
php artisan test                                      # 必須全過（目前 230 個）
```
- `ASSET_URL=/mescits` 一定要帶，否則前端檔路徑會錯。
- 測試沒全過就停止部署。

### 步驟 3：確認快照沒變，打包
```bash
cd "$S" && md5sum -c --quiet ../snap.md5              # 沒有輸出代表沒有變動（storage 底下的除外）
python -I deploy/build.py --no-npm
```
- 輸出在 `deploy/out/`：
  - `app.zip`：約 14.6 MB、7500 個檔案；不含 dev 套件、tests、node_modules、.env。
  - `public/`
  - `x_<亂數>.php`：解壓程式
  - `extractor.txt`：第 1 行是解壓程式的檔名，第 2 行是 token
- `build.py` 會在暫存資料夾重新 `composer install --no-dev` 並執行 `package:discover`。`bootstrap/cache` 裡只會留下正式站用的 `packages.php` 和 `services.php`。
- **出現 WinError 145（資料夾不是空的）**：表示上次失敗留下 `deploy/out/stage`，刪掉後重跑。

### 步驟 4：檢查打包內容
用 Python 比對這次的 `app.zip` 和上一次部署的 `app.zip`，列出新增、變動、刪除的檔案（不看 vendor）：
- 變動的檔案要和這次改的範圍一致。`env.production` 每次都會變（APP_KEY 是隨機的），正式站的 `.env` 會保留，所以沒關係。
- 必須包含 `config/services.php`；不能有 `.env` 或 `*.sqlite`。

### 步驟 5：上傳（順序不能變）
1. **新的前端檔**：
   ```bash
   python -I deploy/mcp_call.py browse_remote '{"profile":"webdav-demo","path":"/build/assets"}' > remote.json
   # 比對 $S/cits/public/build/assets，只上傳遠端沒有的檔案：
   python -I deploy/mcp_call.py upload_items '{"profile":"webdav-demo","paths":["<絕對路徑>",...],"remoteDirectory":"/build/assets","overwrite":true}'
   ```
   舊的前端檔**不要刪**：還開著舊頁面的使用者會用到。
2. **app.zip 和解壓程式**：
   ```bash
   python -I deploy/mcp_call.py upload_items '{"profile":"webdav-app","paths":["<...>/deploy/out/app.zip"],"remoteDirectory":"/","overwrite":true}'
   python -I deploy/mcp_call.py upload_items '{"profile":"webdav-demo","paths":["<...>/deploy/out/x_xxxx.php"],"remoteDirectory":"/","overwrite":true}'
   ```
3. **執行解壓**：
   ```bash
   F=$(sed -n 1p deploy/out/extractor.txt | tr -d '\r'); T=$(sed -n 2p deploy/out/extractor.txt | tr -d '\r')
   curl -s --max-time 300 "https://example.com/mescits/$F?t=$T"
   # 預期：files written = 7xxx / env kept = yes / zip deleted = yes / self deleted = yes
   curl -s -o /dev/null -w "%{http_code}\n" "https://example.com/mescits/$F?t=$T"   # 預期 404
   ```
   - `extractor.txt` 是**兩行**，不要把兩行直接接起來當網址。
   - 解壓時會略過 `storage/`（只補 .gitignore），附件、log、session 都不會被覆蓋。
   - 解壓會清掉 `bootstrap/cache` 裡除了 packages 和 services 以外的快取檔。
4. **最後上傳 manifest**：
   ```bash
   python -I deploy/mcp_call.py upload_items '{"profile":"webdav-demo","paths":["<...>/build/manifest.json","<...>/build/fonts-manifest.json"],"remoteDirectory":"/build","overwrite":true}'
   ```

### 步驟 6：清快取（有新增或修改權限、角色、狀態時才需要）
上傳附錄 C 的一次性腳本並執行，它會清 spatie 權限快取和 `cits.workflow_status_map`，並確認新權限和新路由都讀得到。

### 步驟 7：驗證（第 7 節）並寫紀錄
- `SESSION_LOG.md`（只能用 ASCII）：寫部署時間、快照名稱、測試數、檔案數、驗證結果。
- 有改程式的話，也要在 `.histories/` 留紀錄。

---

## 7. 部署後驗證清單

| # | 項目 | 方法 | 預期 |
|---|---|---|---|
| 1 | manifest | 本機和 `https://…/mescits/build/manifest.json` 比對 md5 | 一致 |
| 2 | 前端檔 | manifest 列出的每個 file 和 css 都用 HEAD 檢查 | 全部 200 |
| 3 | 程式檔 | `download_items '{"profile":"webdav-app","paths":["/app/Services/…"],"localDirectory":"…","overwrite":true}'` 下載 2 到 3 個這次改過的檔案比對 md5 | 和快照一致 |
| 4 | 登入測試 | 附錄 A：以管理員、內部、客戶身分 GET 主要頁面 | 管理員全部 200；內部的 `/admin` 和 `/tickets/create` 是 403；客戶的 `/pm`、`/dev`、`/test`、`/similar/internal` 是 403 |
| 5 | 錯誤 log | `browse_remote '{"profile":"webdav-app","path":"/storage/logs"}'` | 部署之後沒有新寫入（時間是 UTC） |
| 6 | 一次性腳本 | 再呼叫一次 | 404（已自刪） |
| 7 | 新功能 | 實際操作一次這次改的功能 | 正常 |

---

## 8. 只更新手冊

- 適用於只改了 `docs/manual` 的時候。手冊是隨 app.zip 一起部署的，所以流程和第 6 節相同。
- 先確認寫手冊的人說寫完了，並檢查：
  - 每個 `img/…png` 引用都找得到檔案；
  - 章節連結 `](NN-xxx.md)` 都存在；
  - 圖片檔名只能用小寫英數和連字號，路由規定是 `[a-z0-9-]+\.png`。
- 步驟 4 比對時，應該只有 `resources/manual/` 底下的檔案有變動（加上 env.production）。
- 驗證時，用三種身分打開 `/manual` 和每一章：
  - 內部人員看不到第 9 章（系統管理員），會是 404；
  - 客戶看不到第 4、5、6、8、9 章，以及內部畫面的截圖。
- 離線版 `docs/manual/dist`（manual.html、PDF）不會隨部署更新，要另外產生。

---

## 9. 上線後設定（全新安裝，或第一次啟用某個功能時）

1. **管理員密碼**：用 `admin` 登入 → 修改密碼。
2. **郵件**：系統管理 → 郵件設定
   | 欄位 | 值 |
   |---|---|
   | 啟用郵件寄送 | 勾選 |
   | SMTP 主機 | `mail.example.com` |
   | 連接埠 | `465` |
   | 加密方式 | **SSL (465)**（用 587 才選 TLS） |
   | 帳號 | `mescits_bot@example.com` |
   | 密碼 | 這個信箱的密碼（由使用者自己輸入） |
   | 寄件人 Email | 和帳號相同 |
   | 寄件人名稱 | 客戶問題追蹤系統 |
   - 用同一頁的「寄送測試郵件」測試。
   - cPanel → Email Deliverability：確認 SPF、DKIM 是綠色，否則 Gmail 容易把信退回或丟進垃圾郵件。
3. **cron**：cPanel → Cron Jobs → Once Per Five Minutes
   ```
   */5 * * * * /usr/local/bin/php /home/cpuser/app/mescits/artisan schedule:run >> /dev/null 2>&1
   ```
   - 只設這一筆就好。
   - 通知信最多會晚 5 分鐘寄出。
   - **新增每日排程時，分鐘數必須是 5 的倍數**，否則永遠不會執行。目前的每日排程是 00:00（清理暫存）、01:30（補充資料提醒）和 19:25 UTC（台北 03:25，清除超過保留天數的登入紀錄）。
   - 確認方式：看 `outbox` 表的狀態有沒有從 `pending` 變成 `sent`（唯讀 SELECT），或在畫面上觸發一個通知後等 5 分鐘。
4. **專案與成員**：系統管理 → 專案 → 新增；使用者 → 建立帳號；專案 → 成員與角色。
   一個專案至少要有客戶 PM、內部 PM、DEV、Test 各一人。
5. **選項**：系統管理 → 選項管理，可調整會議類型、處理類型、內部單標籤、匯出欄位等。
6. **登入安全**（system_settings，目前沒有畫面，要改請產生 SQL 由使用者執行）：
   - `session.idle_minutes`：閒置幾分鐘自動登出，預設 60，0 = 關閉。開啟時登入頁不顯示「記住我」。
   - `auth_log.keep_days`：登入紀錄保留天數，預設 180，0 = 永久保留。
   - `login.max_attempts`／`login.decay_minutes`：同一個帳號加 IP 的失敗上限（5 次／15 分鐘）。
   - `login.account_max_attempts`／`login.account_decay_minutes`：同一個帳號不論 IP 的失敗上限（10 次／15 分鐘，0 = 關閉）。因為 IP 可以被偽造，這層才擋得住暴力破解。
   - `attachment.allowed_ext`：附件可上傳的副檔名，逗號分隔。
   - 登入紀錄在「系統管理 → 登入紀錄」。IP 取自 `Request::ip()`（主機前面是 openresty 代理，`trustProxies('*')` 會採用代理附加在 `X-Forwarded-For` 最右邊的值）；原始 `X-Forwarded-For` 也一起存。
   - **2026-11-27 實測**：正式站的 openresty 會把連線端自己送的 `X-Forwarded-For` 拿來當 `REMOTE_ADDR`，並刪掉原本的標頭，PHP 拿不到真實 IP。一般瀏覽器記到的 IP 是正確的，但 IP 可以被偽造。要根本解決，請主機商只信任自己代理的 `X-Forwarded-For`。

---

## 10. 出問題時怎麼處理

| 狀況 | 處理 |
|---|---|
| 解壓後網站 500 | 下載 `storage/logs/laravel-<日期>.log` 看錯誤（時間是 UTC）。常見原因：還沒跑 SQL（表不存在）、權限快取沒清（做附錄 C）、`.env` 被改壞 |
| 要回到上一版程式 | 用上一次部署的快照（`snap-…/deploy/out/app.zip` 還在的話）重新上傳並解壓，或從上一次的快照重新打包。**資料庫不能退回**，新增的表和設定留著不會影響舊程式 |
| 頁面樣式跑掉或 JS 404 | manifest 比前端檔先上傳了。補傳缺少的 `/build/assets`；確認前端檔都在之後再重傳 manifest |
| SQL 執行一半出錯 | 不要整份重跑。記下錯誤，用唯讀腳本查哪些已經套用，再產生只補剩下部分的 SQL |
| SQL 跑錯資料庫 | 正式站不會受影響。重新選對資料庫再跑；跑錯的那個資料庫要不要清理，由使用者決定（AI 不能產生刪除語法） |
| 登入測試全部 404 | 一次性腳本沒有設定 `SCRIPT_NAME=<APP_URL 路徑>/index.php`（附錄 A 已經處理） |
| log 出現 1203 max_user_connections | 一次性腳本沒有在每個請求後 `$app['db']->disconnect()` |
| 資料讀出來是空的、全部是字串、密碼一直錯 | PDO 驅動被改回 `pdo_mysql` 了，改回 `nd_pdo_mysql` |
| 通知信沒寄出 | 依序檢查：郵件設定有沒有啟用、cron 有沒有在跑、使用者的「個人設定」有沒有關掉 Email、`outbox` 的狀態和錯誤訊息 |
| 一次性腳本執行後回 404 | token 錯了（腳本不會自刪），或是已經執行過、自刪了 |

---

## 11. 部署紀錄（2026-11-26 這一輪）

| 時間（台北） | 內容 | SQL |
|---|---|---|
| 11:25 | 系統管理員完整權限、手冊 9.0 | 無 |
| 約 12:50 | 已知問題修正、協作功能、客戶進度、PM 結案範圍、更新包多張單 | upgrade-20261126-0431.sql |
| 約 13:30 | 更新包套入即送測 | 無 |
| 14:20 | 撤回送測、會議篩選與匯出 | upgrade-20261126-0559.sql |
| 14:35 | 手冊完整版 | 無 |
| 11-27 | 登入紀錄（含來源 IP，`auth_logs`） | upgrade-20261126-1633.sql |
| 11-27 | 依帳號的登入限制、附件類型、顯示時再過濾 HTML、安全標頭 | upgrade-20261126-1700.sql |
| 11-27 00:05 | 閒置 60 分鐘自動登出（54aafc7） | 無 |

---

## 附錄 A：登入測試腳本範本（`zs_<亂數>.php`，上傳到 webdav-demo 的 `/`）

> 用法：把 `__TOKEN__` 換成隨機的 32 字元 hex，檔名用 `zs_` 加 12 個 hex。上傳後呼叫 `https://…/mescits/zs_xxx.php?t=<token>`。
> 這支腳本只送 GET、只印出狀態碼；它會偽造 file session，跑完刪掉自己和產生的 session 檔。要測的頁面改 `$pages` 就好。

```php
<?php
// One-shot logged-in smoke test: GET pages as admin / internal / client, print status codes only. Self-deletes.
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('__TOKEN__', (string) ($_GET['t'] ?? ''))) { http_response_code(404); exit; }
$appDir = is_dir(dirname(__DIR__, 2).'/app/mescits') ? dirname(__DIR__, 2).'/app/mescits' : dirname(__DIR__);
require $appDir.'/vendor/autoload.php';

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function boot_app(string $appDir) {
    $app = require $appDir.'/bootstrap/app.php';
    $app->usePublicPath(__DIR__);
    $app->instance('request', Request::create('/'));
    $app->make(Kernel::class)->bootstrap();
    return $app;
}

$app = boot_app($appDir);
$pick = function (string $where) {
    return DB::selectOne("SELECT u.id, MIN(pm.project_id) AS pid FROM users u JOIN project_members pm ON pm.user_id = u.id
        WHERE u.status = 'active' AND $where GROUP BY u.id ORDER BY u.id LIMIT 1");
};
$admin = DB::selectOne("SELECT id FROM users WHERE is_admin = 1 AND status = 'active' ORDER BY id LIMIT 1");
$adminPid = DB::selectOne("SELECT MIN(id) AS pid FROM projects");
$who = [
    'admin' => $admin ? (object) ['id' => $admin->id, 'pid' => $adminPid->pid] : null,
    'internal' => $pick("u.user_type = 'internal' AND u.is_admin = 0"),
    'client' => $pick("u.user_type = 'client' AND u.is_admin = 0"),
];
$app['db']->disconnect();
$pages = ['/pm', '/dev', '/test', '/timeline', '/reports', '/mes', '/meetings', '/meetings/create', '/tickets', '/tickets/create',
    '/flows', '/project-admin', '/manual', '/manual/09-system-admin', '/manual/11-meetings', '/notifications', '/admin'];
$sessFiles = [];
foreach ($who as $role => $u) {
    if (!$u) { echo "$role: no user\n"; continue; }
    $app = boot_app($appDir);
    $store = $app['session']->driver();
    $store->setId(null);
    $store->start();
    $store->put('login_web_'.sha1(Illuminate\Auth\SessionGuard::class), $u->id);
    $store->put('project_id', (int) $u->pid);
    $store->save();
    $sid = $store->getId();
    $app['db']->disconnect();
    $sessFiles[] = $sid;
    $name = $app['config']['session.cookie'];
    $enc = $app['encrypter'];
    $cookie = $enc->encrypt(CookieValuePrefix::create($name, $enc->getKey()).$sid, false);
    $line = [];
    foreach ($pages as $p) {
        $a = boot_app($appDir);
        $k = $a->make(Kernel::class);
        $base = rtrim((string) parse_url($a['config']['app.url'], PHP_URL_PATH), '/');
        $req = Request::create(rtrim($a['config']['app.url'], '/').$p, 'GET', [], [$name => $cookie], [], [
            'HTTPS' => 'on', 'SCRIPT_NAME' => $base.'/index.php', 'PHP_SELF' => $base.'/index.php', 'SCRIPT_FILENAME' => __DIR__.'/index.php',
        ]);
        try {
            $res = $k->handle($req);
            $code = $res->getStatusCode();
            if ($code >= 300 && $code < 400) { $code .= '>'.parse_url((string) $res->headers->get('Location'), PHP_URL_PATH); }
            $k->terminate($req, $res);
            $a['db']->disconnect();
        } catch (Throwable $e) {
            $code = 'EX:'.get_class($e);
        }
        $line[] = "$p=$code";
    }
    echo "$role (user #{$u->id}, project #{$u->pid}): ".implode(' ', $line)."\n";
}
foreach ($sessFiles as $sid) { @unlink($app['config']['session.files'].'/'.$sid); }
echo 'self deleted = '.(@unlink(__FILE__) ? 'yes' : 'NO')."\n";
```

## 附錄 B：唯讀檢查腳本範本（`x_vq_<亂數>.php`）

> **只能寫 SELECT 和 SHOW。** 腳本一開始就會先自刪。上傳前用 `grep -iE "insert |update |delete |drop |alter |truncate "` 檢查，只能出現在註解裡。

```php
<?php
// One-shot READ-ONLY check of the upgrade SQL (SELECT / SHOW only). Self-deletes.
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('__TOKEN__', (string) ($_GET['t'] ?? ''))) { http_response_code(404); exit; }
@unlink(__FILE__);
$appDir = is_dir(dirname(__DIR__, 2).'/app/mescits') ? dirname(__DIR__, 2).'/app/mescits' : dirname(__DIR__);
require $appDir.'/vendor/autoload.php';
$app = require $appDir.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->instance('request', Illuminate\Http\Request::create('/'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$q = function (string $label, string $sql) { echo "== $label\n"; foreach (DB::select($sql) as $r) echo '  '.json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; };
try {
    // examples - replace with the checks you need (SELECT / SHOW only)
    $q('migrations', "SELECT migration, batch FROM migrations ORDER BY id DESC LIMIT 5");
    $q('withdraw transition', "SELECT scope, from_code, to_code, permission FROM workflow_transitions WHERE scope='issue' AND from_code='test_pending' AND to_code='dev_doing'");
    $q('event label', "SELECT code, name FROM lookup_items WHERE category='event_label' AND code='test_withdrawn'");
    $q('export columns', "SELECT category, COUNT(*) n FROM lookup_items WHERE category IN ('export_meetings','export_meeting_items') GROUP BY category ORDER BY category");
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.substr($e->getMessage(), 0, 300)."\n";
}
$app['db']->disconnect();
echo 'self deleted = '.(is_file(__FILE__) ? 'NO' : 'yes')."\n";
```

## 附錄 C：清快取腳本範本（`x_pc_<亂數>.php`）

```php
<?php
// One-shot: clear spatie permission cache + workflow status map cache after deploy. Self-deletes.
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('__TOKEN__', (string) ($_GET['t'] ?? ''))) { http_response_code(404); exit; }
@unlink(__FILE__);
$appDir = is_dir(dirname(__DIR__, 2).'/app/mescits') ? dirname(__DIR__, 2).'/app/mescits' : dirname(__DIR__);
require $appDir.'/vendor/autoload.php';
$app = require $appDir.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->instance('request', Illuminate\Http\Request::create('/'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
echo 'permission cache cleared = '.(app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions() ? 'yes' : 'no')."\n";
echo 'status map cache cleared = '.(Illuminate\Support\Facades\Cache::forget('cits.workflow_status_map') ? 'yes' : 'no (not cached)')."\n";
$perms = app(Spatie\Permission\PermissionRegistrar::class)->getPermissions()->pluck('name')->all();
echo 'issue.tag loaded = '.(in_array('issue.tag', $perms, true) ? 'yes' : 'NO')."\n";
echo 'ticket.needinfo loaded = '.(in_array('ticket.needinfo', $perms, true) ? 'yes' : 'NO')."\n";
echo 'route collab.needinfo.request = '.(app('router')->has('collab.needinfo.request') ? 'yes' : 'NO')."\n";
echo 'route dev.packages.issues.add = '.(app('router')->has('dev.packages.issues.add') ? 'yes' : 'NO')."\n";
$app['db']->disconnect();
echo 'self deleted = '.(is_file(__FILE__) ? 'NO' : 'yes')."\n";
```
