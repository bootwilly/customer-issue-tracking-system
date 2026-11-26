# CITS 部署說明

## 目前狀態（2026-11-24 凌晨部署）

- 程式碼已部署：
  - Laravel 本體：`~/app/mescits`（網址讀不到）
  - 公開檔案：`~/public_html/mescits`
- 已驗證：
  - `https://example.com/mescits/login` 可以顯示登入頁
  - HTTP 會 301 轉到 HTTPS
  - 有 HSTS 標頭，Cookie 設了 secure 和 httponly
  - `/.env` 回 404
- **還不能登入**：資料庫還沒建表，`.env` 的資料庫密碼也還沒填。

## 你要做的 3 步

### 1. 建資料表（phpMyAdmin）

cPanel → phpMyAdmin → 左側點 `cpuser_mescits` → 切到「SQL」分頁，貼上 `deploy/out/install.sql` 的全部內容後執行。

- 只能在**空的資料庫**執行一次。
- 內容是 41 張表、初始資料（狀態、流程、權限、角色、選項），以及 admin 帳號。

### 2. 填資料庫密碼

cPanel → 檔案管理員 → 打開 `app/mescits/.env`，把這一行

```
DB_PASSWORD=__FILL_IN_DB_PASSWORD__
```

改成你的資料庫密碼後存檔。

- 這個 `.env` 之後重新部署也會保留，不會被覆蓋。
- `APP_KEY` 已經隨機產生好了，**不要換**。換掉的話，之後存進去的 SMTP 密碼會解不開。

### 3. 設定 Cron

cPanel → Cron 工作 → 新增一個，頻率選「每分鐘」(`* * * * *`)，指令填：

```
/usr/local/bin/php /home/cpuser/app/mescits/artisan schedule:run >/dev/null 2>&1
```

- 如果主機的命令列 PHP 不是 8.5，排程會失敗，請把 PHP 路徑換成 `/opt/cpanel/ea-php85/root/usr/bin/php`。這個路徑是推測的，請在 cPanel 確認。
- 排程內容：每分鐘寄出 outbox 裡的通知信、跑 queue；每天清一次暫存檔。

## 登入

- 帳號：`admin`
- 密碼：在專案根目錄的 `ADMIN_PASSWORD.local.txt`（8 位數字）。這個檔案不會進 git，記下密碼後請自行刪除。
- 第一次登入後：
  1. 到「系統管理」建立專案。
  2. 建立使用者並加入專案、勾選角色。
  3. 到「MES 頁面」註冊功能頁面。

## 重新部署

```
php deploy/make_install_sql.php   # 只有資料表結構有變動時才需要
python deploy/build.py               # 產生 app.zip、public/、一次性解壓腳本
```

上傳和解壓目前是由 Claude 透過 MCP 執行的。流程如下：

1. 把 `app.zip` 上傳到 `webdav-app`。
2. 把解壓腳本上傳到 `webdav-demo`。
3. 開啟「`腳本網址?t=token`」執行解壓。腳本執行完會刪掉 zip 和自己。
4. 把 `public/` 上傳到 `webdav-demo`。

如果資料表結構有變動，要另外產生 ALTER 用的 SQL，由你在 phpMyAdmin 執行。
