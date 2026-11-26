# CITS — Customer Issue Tracking System（客戶問題追蹤系統）

讓**客戶**回報問題，**PM、DEV、Test** 依流程處理到上線的追蹤系統。附會議紀錄、流程圖、Email 通知和線上操作手冊。
用 Laravel 13 開發，可以部署在一般的 cPanel 共享主機上（不需要 SSH），也可以部署在 VPS。

> ⚖️ **授權：Apache License 2.0**。內附的第三方元件有各自的授權條件（特別是 draw.io），使用前請先看下方的 [授權注意事項](#授權注意事項重要)。

---

## 功能

| 對象 | 功能 |
|---|---|
| 客戶 | 開單（異常回報或新需求）、修訂內容（保留版本差異）、留言、附件、取消、結案、回報仍有異常；看得到**處理進度**（評估中 → 修正中 → 測試中 → 待更新 → 已更新）；收到「要求補充資料」時會有提醒 |
| PM | 接收客戶單、回覆、處置；建立內部單（一張客戶單可以對應多張內部單，反之亦然）；派工給 DEV；標籤、相依關係（例如要等另一張單上線才能開發）；重複單提示；「我的查詢」；匯出 Excel |
| DEV | 開發清單、排程、延期申請；**更新包**：勾選多張單一次套入就送測，並指定測試人員；移出更新包就撤回送測 |
| Test | 測試、排程測試、退回（仍有異常）、測試通過、上線 |
| 所有人 | 會議（討論事項、紀錄、分享給客戶、可匯出 Excel）、流程圖（自架 draw.io 和 Mermaid）、MES 頁面樹、時序總覽、報表、通知（站內和 Email）、關注 |
| 管理員 | 使用者、專案、成員與角色、選項管理（狀態名稱、匯出欄位等）、郵件設定、資源回收筒、操作紀錄；**登入紀錄**（登入成功／失敗、登出、閒置登出、改密碼等，含來源 IP，可篩選和匯出 Excel） |

角色：客戶 PM、客戶檢視、內部 PM、DEV、Test、專案管理員，另外還有系統管理員。權限以專案為單位指派。

安全：閒置 60 分鐘自動登出（`session.idle_minutes`，0 = 關閉；開啟時不提供「記住我」）；登入嘗試次數限制；登入紀錄保留 180 天（`auth_log.keep_days`）。

---

## 架構

### 技術
- **後端**：PHP 8.3 以上（以 8.5 測試）、Laravel 13、spatie/laravel-permission（角色以專案為單位）、openspout（Excel）、symfony/html-sanitizer
- **前端**：Blade、Alpine.js、Tailwind CSS 4、Vite；TipTap 編輯器；Mermaid；自架 draw.io
- **資料庫**：MariaDB、MySQL（正式環境），SQLite（開發和測試）
- **測試**：Pest（226 個 feature test）

### 分層
```
Route（routes/modules/*.php）
  → Controller（app/Http/Controllers）  驗證輸入、組畫面
    → Service（app/Services）           業務邏輯、權限、流程；不直接存取資料庫
      → Repository（app/Repositories）  所有資料庫存取
        → Model（app/Models）
```
- **業務規則放在資料表，不寫死在程式裡**：狀態、狀態轉換（含需要的權限和必填欄位）、欄位編輯權限、選項、匯出欄位、系統設定都存在 `workflow_statuses`、`workflow_transitions`、`field_edit_rules`、`lookup_items`、`system_settings`。預設值寫在 `cits/database/seeders/data/workflow.php`，管理員可以在畫面上調整。
- **時間**：一律存 UTC，畫面顯示台北時間。
- **通知**：事件 → 站內通知加 `outbox`（Email 佇列）→ 排程（`schedule:run`）寄出。不需要常駐的 queue worker。

### 內部單流程
```
PM 階段（新建／待討論） → DEV（待開發 → 開發中） → TEST（待測試 → 測試中／排程測試） → RELEASE（待更新 → 已上線）
                                 ↑  測試不通過退回（仍有異常）  │
另有：取消、不處理（連結了客戶單時，要填寫給客戶看的說明）
```
客戶單的所有內部單都上線後，系統會自動轉成「待結案」並通知客戶。

### 目錄
```
cits/                     Laravel 應用程式
  app/Services/           Client、Pm、Dev、Test、Meeting、Flow、Collab、Export、Report……
  app/Repositories/
  database/migrations/    資料表結構（15 個）
  database/seeders/data/workflow.php   狀態、流程、選項、權限的預設資料
  resources/views/        Blade 畫面
  routes/modules/         各模組的路由
  tests/Feature/          Pest 測試
  public/drawio/          自架的 draw.io v32.7.4（第三方，見授權）
docs/manual/              線上操作手冊（Markdown 和截圖，部署時打包進系統）
docs/                     DEPLOYMENT_GUIDE、PLATFORM_DEV_RECORD、各功能的規劃文件
deploy/                   cPanel 部署工具：build.py（打包）、make_install_sql.php／make_upgrade_sql.php（產生 SQL）
licenses/                 第三方授權全文
```

---

## 部署方式

| 方式 | 適合 | 怎麼建資料庫 | 文件 |
|---|---|---|---|
| **A. 本機試用** | 想先看看、開發 | SQLite，`php artisan migrate --seed` | [AGENT_DEPLOY.md §3](AGENT_DEPLOY.md) |
| **B. Linux VPS（有 SSH）** | 一般正式環境（建議） | `php artisan migrate --seed --force` | [AGENT_DEPLOY.md §4](AGENT_DEPLOY.md) |
| **C. cPanel 共享主機（沒有 SSH）** | 只有虛擬主機 | 在自己電腦用 `deploy/make_install_sql.php` 產生 SQL，再到 phpMyAdmin 執行 | [AGENT_DEPLOY.md §5](AGENT_DEPLOY.md)、[docs/DEPLOYMENT_GUIDE.md](docs/DEPLOYMENT_GUIDE.md) |

**用 AI agent 部署**：把 [AGENT_DEPLOY.md](AGENT_DEPLOY.md) 第 0 節的指令貼給你的 coding agent，填上部署目標、網址和資料庫。

### 最快試用（路線 A）
```bash
cd cits
composer install && npm ci
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
ADMIN_INITIAL_PASSWORD='<自訂密碼>' php artisan migrate --seed   # 建立管理員帳號 admin
php artisan db:seed --class=DemoSeeder                          # 選用：示範資料（密碼都是 123456，只限本機）
npm run build && php artisan serve                              # http://localhost:8000
```

### 部署前要知道
- 環境需求：PHP 8.3 以上（含 mbstring、intl、gd、zip、fileinfo、pdo_mysql 或 pdo_sqlite）、Composer、Node 20 以上（建置用）、MariaDB 10.6 以上或 MySQL 8 以上、**cron**（寄 Email 和背景工作）、正式環境要有 HTTPS。
- **網站根目錄必須指向 `cits/public`**。不要把 `cits/` 整個目錄公開，否則 `.env` 和附件會外洩。
- **repo 不附現成的 SQL 檔**：SQL 會帶有管理員密碼雜湊，請照文件自己產生，每個人的初始密碼都不同。
- 共享主機如果讀出來的資料錯亂、登入一直失敗，請把 PHP 擴充從 `pdo_mysql` 改成 **`nd_pdo_mysql`**。
- 郵件在系統的「系統管理 → 郵件設定」頁面填寫，不用寫在 `.env`。

---

## 授權注意事項（重要）

### 本專案
- 以 **Apache License 2.0** 授權（[`LICENSE`](LICENSE)），著作權聲明見 [`NOTICE`](NOTICE)。
- 你可以商用、修改、散布，包括閉源的衍生作品。條件是：
  1. 附上 `LICENSE` 和 `NOTICE`；
  2. **修改過的檔案要註明有修改**；
  3. 不得使用原作者的名稱或商標做背書。
- 授權中包含專利授權；如果你對本專案提起專利訴訟，你取得的專利授權會終止。
- 本軟體依「現狀」提供，不附任何保證。

### 第三方元件（完整清單和全文：[`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)、[`licenses/`](licenses/)）
- **沒有強傳染性授權（GPL、AGPL）**，你的衍生作品不會被迫開源。
- 有多種授權可選的套件，本專案的選擇：nette/schema 和 nette/utils 用 **BSD-3-Clause**；DOMPurify 用 **Apache-2.0**。
- 其餘 PHP 和 JS 套件都是 MIT、ISC、BSD、Apache-2.0 等寬鬆授權。部署或散布成品時，請保留 `THIRD_PARTY_NOTICES.md` 和 `licenses/`。

### ⚠️ 內附的 draw.io（`cits/public/drawio/`）有額外條件
| 部分 | 授權 | 你要做的事 |
|---|---|---|
| draw.io 本體 | Apache-2.0（JGraph Ltd） | 保留 `cits/public/drawio/LICENSE`；本專案修改了 `js/PreConfig.js`（見 `CITS_VERSION.txt`） |
| `js/libavoid-js/` | **LGPL-2.1** | 保持它是獨立的檔案，不要和其他程式碼合併打包；保留它的 LICENSE；**如果修改了它，修改的部分要以 LGPL 公開** |
| `templates/` | **CC BY 4.0** | 使用或散布時要標示出處（draw.io / JGraph Ltd），修改過要註明 |
| `img/`、`shapes/`、`stencils/` 圖示集 | 有使用限制 | **未經 JGraph 書面許可，不得用於 Atlassian 產品、Atlassian Marketplace 或其外掛**；使用者匯出的圖不受限制 |

> 以上是整理說明，不是法律意見；請以各授權原文為準。

---

## 文件

| 文件 | 內容 |
|---|---|
| [AGENT_DEPLOY.md](AGENT_DEPLOY.md) | 給下載者：用 AI agent 部署（本機、VPS、cPanel） |
| [docs/DEPLOYMENT_GUIDE.md](docs/DEPLOYMENT_GUIDE.md) | cPanel 沒有 SSH 時的完整部署手冊：凍結快照、上傳順序、驗證、出問題時怎麼處理 |
| [docs/PLATFORM_DEV_RECORD.md](docs/PLATFORM_DEV_RECORD.md) | 平台與開發紀錄：模組、流程、資料表、通知、開發工具和踩過的坑 |
| [docs/manual/](docs/manual/) | 操作手冊，系統內的 `/manual` 也看得到 |
| [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) | 第三方授權清單和各授權的義務 |
| `HANDOVER.md`、`PROJECT_CONTEXT.md`、`SESSION_LOG.md` | 原作者的交接文件、長期規則、開發日誌 |

## 開發

```bash
cd cits && php artisan test     # 全部要通過
npm run dev                     # 前端即時更新
```
開發規則：Service 不直接存取資料庫；業務值放進 seed 資料或選項，不寫死在程式裡；新功能要附 Pest 測試；資料表變更要寫 migration。詳見 [AGENT_DEPLOY.md §7](AGENT_DEPLOY.md)。
