# 資安稽核修補第三批（2026-11-27 台北時間）

依據 `docs/security-audit-20261127.md`，接續第一、二批。

| 項目 | 檔案 | 修改 |
|---|---|---|
| M-05 換主機帶走舊密碼 | `MailSettingService::update` | SMTP 主機變更而且沒有填新密碼時，清掉舊密碼 |
| M-05 STARTTLS 降級 | `OutboxService::applySmtpConfig` | 加密方式選 `tls` 時設定 `require_tls`，伺服器不提供 STARTTLS 就不寄 |
| M-05 解密失敗 500 | `SettingRepository::get` | 解不開（APP_KEY 換過）時寫 warning log 並視為沒有設定 |
| M-06 會議 Markdown 預覽 | `MeetingController::document` | 回應加上嚴格 CSP（只允許本站 script，禁止 object、表單、base） |
| M-06 drawio | `public/drawio/js/PreConfig.js`、`deploy/build.py`（`DRAWIO_DROP`） | 強制 `plugins=0`、`pwa=0`，刪除網址參數 `p`、`mode`、`gapi-stealth`；不再部署 `onedrive3.html`、`teams.html`，解壓時從主機刪除 |
| M-07 .htaccess | `public/.htaccess` | `Options -Indexes` 移出 `mod_negotiation` 區塊（主機沒有這個模組，原本沒生效）；拒絕點開頭的檔案和 zip／sql／sqlite／bak／log；trailing slash 轉址排除 `//`；靜態檔用 `Header always setifempty` 補 nosniff、X-Frame-Options、Referrer-Policy。上線前先在正式站的暫時子目錄測過 LiteSpeed 接受這些指令，也確認不會覆蓋 PHP 設定的標頭 |
| M-07 解壓程式 | `deploy/build.py` | token 改成只接受 POST 的 `X-Deploy-Token` 標頭；用 flock 確保一次只跑一個；`register_shutdown_function` 確保出錯或逾時也會自刪；刪除 zip 裡沒有的 `routes/` 檔案（zip 沒有 `routes/web.php` 時不刪）；drawio.zip 改傳到私有的 app 目錄 |
| L-02 | `ContentService::clean`、`ManualService::markdown`、`SitemapParser::parse` | 移除 strip_tags 退回邏輯；手冊的 internal 標記沒有正確關閉時，從那裡之後對客戶全部隱藏（現有 12 章檢查過都不受影響）；sitemap 拒絕 doctype |
| L-03 | `ReportController::safe` | CSV 公式注入的前導字元和 Excel 匯出一致（加上 tab、CR） |
| L-04 | `DraftController`、`DraftService`、`DraftRepository`、`resources/js/editor/index.js`、`layouts/app.blade.php` | 草稿內容上限 2,000,000 字元；每人最多 200 份，超過時刪最舊的；瀏覽器端草稿的 key 加上使用者 id，載入時清掉其他帳號和舊格式的 key，登出（含閒置登出）時清掉全部 |
| L-05 | `ProjectController`、`UserRepository` | 單號前綴必須唯一；刪除使用者時的歷史檢查補上流程圖、會議、補件、標籤、相依；一併刪除 api_tokens、watchers、saved_queries、meeting_reads、flow editors |

沒有做：L-05 的 LIKE 跳脫（只影響搜尋準確度，而且 SQLite 需要 ESCAPE 子句，改動面大）；M-05 的內網 IP 封鎖（cPanel 主機常用 localhost SMTP，封鎖會擋到正常設定）；M-07 的解壓到暫存目錄再 rename（storage 和 .env 在同一個目錄，整個切換風險高）。

測試：新增 `tests/Feature/SecurityAuditBatch3Test.php`（6 個），全部 256 個通過。drawio 用本機伺服器實測過（惡意參數被清掉、沒有外部請求、編輯器 configure／init／load 正常）。新的解壓程式在本機模擬過主機目錄結構。
