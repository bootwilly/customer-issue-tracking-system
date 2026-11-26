# 用 AI Agent 快速部署 CITS

> 給下載這個專案的人。把本文件交給你的 coding agent（Claude Code、Codex、Gemini CLI、Cursor 等），它就能照著把 CITS 跑起來。
> 下面先給一段可以直接複製的指令，後面是 agent 要照做的詳細步驟。

CITS（Customer Issue Tracking System）是用 Laravel 13 寫的客戶問題追蹤系統，功能包括：
- 客戶單：開單、修訂、留言、處理進度、結案
- 內部單：PM、DEV、TEST、上線四個階段，更新包送測
- 會議紀錄（可匯出 Excel）、流程圖（自架 draw.io）、MES 頁面、報表
- Email 通知、線上操作手冊

授權：Apache-2.0（見 `LICENSE`、`NOTICE`、`THIRD_PARTY_NOTICES.md`）。

---

## 0. 直接貼給 Agent 的指令

```text
請閱讀 AGENT_DEPLOY.md，幫我部署 CITS：
- 部署目標：<本機試用 | Linux VPS（有 SSH） | cPanel 共享主機（沒有 SSH）>
- 網址：<例如 http://localhost:8000 或 https://example.com/cits>
- 資料庫：<SQLite（只限試用） | MySQL/MariaDB，主機／資料庫名／帳號由我提供>
規則：
1. 先檢查環境需求（第 1 節），缺什麼先告訴我，不要自行安裝系統套件。
2. 密碼、APP_KEY 等機密由我自己填進 .env，不要在對話中顯示，也不要 commit。
3. 不要對已經有資料的正式資料庫執行 migrate:fresh、db:wipe、DROP、TRUNCATE 或 DELETE。
4. 每一步完成就照第 6 節驗證，失敗時把錯誤貼給我，不要跳過。
```

---

## 1. 環境需求

| 項目 | 需求 |
|---|---|
| PHP | **8.3 以上**（開發和正式站都用 8.5 測過）。需要的擴充：pdo_mysql 或 pdo_sqlite、mbstring、intl、gd、fileinfo、openssl、zip、curl、xml、ctype、tokenizer |
| Composer | 2.x |
| Node.js | 20 以上，加上 npm（只在建置前端時需要，正式主機上可以沒有） |
| 資料庫 | **MariaDB 10.6 以上或 MySQL 8 以上**（utf8mb4），或 **SQLite**（只適合試用） |
| Web 伺服器 | Apache、LiteSpeed（支援 `.htaccess`），或 Nginx（網站根目錄要指向 `cits/public`） |
| 排程 | cron 每 1～5 分鐘執行一次 `php artisan schedule:run`，用來寄 Email 和跑背景工作 |
| HTTPS | 正式環境必須有。正式環境會強制轉 HTTPS，Session cookie 也只走 HTTPS |

**常見的坑**
- 部分共享主機的預設 `pdo_mysql` 和新版 PHP 不相容：讀出來的資料都是字串、欄位錯亂、登入一直失敗。請在主機的 PHP 擴充設定改用 **`nd_pdo_mysql`（mysqlnd）**。
- MySQL 的 `sql_mode` 如果是非嚴格模式，太長的字串會被直接截斷而不會報錯。程式本身有控制長度，但自己改資料時要留意。

---

## 2. 專案結構

```
cits/                 Laravel 應用程式（所有指令都在這裡執行）
  app/                Controller → Service（業務邏輯）→ Repository（資料存取）
  database/seeders/data/workflow.php   狀態、流程、選項、權限的預設資料
  public/drawio/      自架的 draw.io（流程圖編輯器）
docs/manual/          線上操作手冊（部署時會打包進 resources/manual）
docs/DEPLOYMENT_GUIDE.md   cPanel 沒有 SSH 時的完整部署手冊
deploy/               打包工具（build.py、產生 SQL 的程式）
```

---

## 3. 路線 A：本機試用（約 5 分鐘）

```bash
cd cits
composer install
npm ci
cp .env.example .env
php artisan key:generate
# 用 SQLite：.env 預設就是 DB_CONNECTION=sqlite
touch database/database.sqlite            # Windows PowerShell 改用：New-Item database/database.sqlite
# 建立管理員：先設定 ADMIN_INITIAL_PASSWORD 環境變數再跑 seed（帳號是 admin）
ADMIN_INITIAL_PASSWORD='<自訂密碼>' php artisan migrate --seed
php artisan db:seed --class=DemoSeeder    # 選用：示範專案和帳號，密碼都是 123456，只限本機使用
npm run build
php artisan serve                          # 開啟 http://localhost:8000
```
- Windows PowerShell 設定環境變數的寫法：`$env:ADMIN_INITIAL_PASSWORD='...'; php artisan migrate --seed`。
- 開發時可以用 `npm run dev` 即時更新前端。
- 跑測試：`php artisan test`，應該全部通過。

---

## 4. 路線 B：Linux VPS（有 SSH，建議）

1. **取得程式碼**，放到例如 `/var/www/cits`。
2. **安裝**：
   ```bash
   cd /var/www/cits/cits
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build                 # 網站放在子目錄時：ASSET_URL=/cits npm run build
   cp .env.example .env && php artisan key:generate
   ```
3. **設定 `.env`**（密碼由使用者自己填）：
   ```dotenv
   APP_NAME=CITS
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://example.com            # 放在子目錄時寫成 https://example.com/cits
   APP_LOCALE=zh_TW
   LOG_CHANNEL=daily
   LOG_LEVEL=warning
   DB_CONNECTION=mariadb                  # MySQL 填 mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=cits
   DB_USERNAME=cits
   DB_PASSWORD=                           # 由使用者填入
   DB_CHARSET=utf8mb4
   DB_COLLATION=utf8mb4_unicode_ci        # MariaDB 11 以上可以用 utf8mb4_uca1400_ai_ci
   SESSION_DRIVER=file
   SESSION_SECURE_COOKIE=true
   CACHE_STORE=file
   QUEUE_CONNECTION=database
   MAIL_MAILER=log                        # 郵件改在系統的「郵件設定」頁面填寫，這裡維持 log
   ```
4. **建立資料表和預設資料**（只能對**空的資料庫**這樣做）：
   ```bash
   ADMIN_INITIAL_PASSWORD='<自訂密碼>' php artisan migrate --seed --force
   ```
5. **權限**：`storage/` 和 `bootstrap/cache/` 要讓 Web 伺服器的使用者可以寫入：
   ```bash
   chown -R www-data:www-data storage bootstrap/cache
   chmod -R ug+rwX storage bootstrap/cache
   ```
6. **Web 伺服器**：網站根目錄（document root）**指到 `cits/public`**。不能把 `cits/` 整個目錄公開，否則 `.env`、`storage` 會被下載。
   - Nginx 範例：`root /var/www/cits/cits/public; location / { try_files $uri $uri/ /index.php?$query_string; }`，再加上 PHP-FPM 設定。
7. **cron**（以 Web 伺服器的使用者執行）：
   ```
   * * * * * cd /var/www/cits/cits && php artisan schedule:run >> /dev/null 2>&1
   ```
8. **可選的效能設定**：`php artisan config:cache && php artisan view:cache`。以後改 `.env` 要再跑一次 `config:cache`。
9. 接著做第 6 節的驗證和上線後設定。

**之後更新版本**：
```bash
git pull
composer install --no-dev -o
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
```
`migrate` 會補上新的資料表；新的預設資料（選項、權限）用 `php artisan db:seed --force` 補，這個 seed 可以重複執行，不會覆蓋管理員在畫面上改過的名稱。

---

## 5. 路線 C：cPanel 共享主機（沒有 SSH）

這個專案原本就部署在這種環境，完整流程見 **`docs/DEPLOYMENT_GUIDE.md`**。重點如下：
1. **目錄分開**：
   - `~/public_html/<子目錄>` 只放 `cits/public` 的內容；
   - Laravel 核心放在 `~/app/<名稱>`，必須在 public_html 之外。
   - `deploy/build.py` 產生的 `public/index.php` 會自動改成載入 `../../app/mescits/...`。**如果你的目錄名稱不同，要修改 `build.py` 裡的 `app/mescits`。**
2. **本機設定**：建立 `deploy/deploy.local.json`（這個檔案不進 git）：
   ```json
   {"php": "C:\\path\\to\\php.exe", "composer": "C:\\path\\to\\composer.phar",
    "app_url": "https://example.com/mescits", "db_database": "cpuser_mescits", "db_username": "cpuser_mescits"}
   ```
   也可以改用環境變數 `CITS_PHP`、`CITS_COMPOSER`、`CITS_APP_URL`、`CITS_DB_DATABASE`、`CITS_DB_USERNAME`。
3. **資料庫**：用 `php deploy/make_install_sql.php` 產生 `deploy/out/install.sql`（做法見 DEPLOYMENT_GUIDE 3.2），在 phpMyAdmin **選好空的資料庫**後執行。
4. **打包**：
   ```bash
   cd cits && ASSET_URL=/<子目錄> npm run build
   cd .. && python deploy/build.py --no-npm
   ```
   輸出：`deploy/out/app.zip`、`deploy/out/public/`、一次性的解壓程式 `x_*.php`，以及寫著解壓程式檔名和 token 的 `extractor.txt`。
5. **上傳**：用你自己的方式上傳（cPanel 檔案管理員、WebDAV 用戶端或 FTP）：
   - `app.zip` 傳到核心目錄；
   - `public/` 的內容和 `x_*.php` 傳到公開目錄；
   - 用瀏覽器開 `https://<網址>/<x_*.php>?t=<token>` 解壓。解壓完成後這支程式會自刪，`.env` 由範本建立。
   - `deploy/mcp_call.py` 是原作者本機專用的上傳工具，**你的環境用不到**。
6. 用 cPanel 的檔案管理員編輯核心目錄的 `.env`，填入 `DB_PASSWORD`。
7. cron（cPanel → Cron Jobs）：`*/5 * * * * /usr/local/bin/php /home/<cPanel帳號>/app/<名稱>/artisan schedule:run >> /dev/null 2>&1`。
   - 如果只能每 5 分鐘一次，**每日排程的分鐘數必須是 5 的倍數**，否則不會執行。

---

## 6. 驗證與上線後設定

**驗證**（每條路線都要做）
- [ ] 打開首頁會導到登入頁；用 `admin` 和你設定的密碼可以登入。
- [ ] 這些頁面都能正常打開：系統管理（`/admin`）、會議（`/meetings`）、操作手冊（`/manual`）。
- [ ] 瀏覽器開發者工具沒有 404 的 JS 或 CSS。如果有，通常是 `ASSET_URL` 或 `APP_URL` 和實際網址的子目錄不一致。
- [ ] `storage/logs/` 沒有新的錯誤。
- [ ] 本機跑 `php artisan test` 全部通過。

**上線後設定**
1. **立刻修改 admin 密碼**。
2. 系統管理 → **郵件設定**：填寫 SMTP（465 對應加密方式「SSL」，587 對應「TLS」），再按「寄送測試郵件」。
3. 系統管理 → **專案**：新增專案；**使用者**：建立帳號；在專案的「成員與角色」指派角色。一個專案至少需要客戶 PM、內部 PM、DEV、Test 各一人。
4. 確認 cron 有在跑：在畫面上觸發一個通知（例如客戶開單），等一個排程週期後，收件人應該收到 Email。

---

## 7. 給 Agent 的開發規則（要修改程式碼時）

- 分層：Controller → Service → Repository。**Service 不可直接操作資料庫**（不用 DB facade、不寫 Eloquent 查詢）。
- **不可寫死業務值**：狀態、選項、標籤、匯出欄位都從 `lookup_items`、`workflow_*`、`system_settings` 讀取，預設值寫在 `database/seeders/data/workflow.php`。
- 時間存 UTC，畫面顯示 Asia/Taipei（helper `tw()`）。
- 改完要跑 `php artisan test` 並全部通過；新功能要附 Pest 測試。
- 資料表變更要寫 migration。修改既有資料的 migration 要用 `DB::statement` 寫原生 SQL，因為 `SeedDataLengthTest` 會用 pretend 模式編譯所有 migration。
- 更多背景知識見 `docs/PLATFORM_DEV_RECORD.md`；`PROJECT_CONTEXT.md` 是原作者的長期規則，可以參考。

---

## 8. 常見問題

| 狀況 | 原因和處理方式 |
|---|---|
| 500 錯誤 | 看 `storage/logs/laravel-*.log`。常見原因：`.env` 沒有 `APP_KEY`、資料庫連不上、`storage` 沒有寫入權限 |
| 頁面沒有樣式 | 沒有執行 `npm run build`，或是 `ASSET_URL` 和子目錄不一致 |
| 登入一直失敗、資料讀出來是空的 | 主機的 `pdo_mysql` 不相容，改用 `nd_pdo_mysql`（見第 1 節） |
| 收不到 Email | 依序檢查：郵件設定是否啟用、cron 是否有在跑、使用者個人設定是否關掉了 Email 通知、`outbox` 表的錯誤訊息 |
| 流程圖編輯器打不開 | 確認 `public/drawio/` 有完整上傳（約 3000 個檔案） |
| 所有網址都 404（放在子目錄時） | `.htaccess` 沒有生效（Apache 要開啟 AllowOverride），或 `APP_URL` 沒有包含子目錄 |

---

## 9. 授權

- 本專案：Apache-2.0。
- 第三方元件的授權和必須遵守的條件見 `THIRD_PARTY_NOTICES.md`，授權全文在 `licenses/`。
- 需要特別注意的是內附的 draw.io：
  - libavoid-js 是 LGPL-2.1，要保持獨立檔案；
  - 範本是 CC BY 4.0，使用時要標示出處；
  - 圖示集未經許可不得用於 Atlassian 產品。
- 散布或部署時，請保留 `LICENSE`、`NOTICE`、`THIRD_PARTY_NOTICES.md` 和 `licenses/`。
