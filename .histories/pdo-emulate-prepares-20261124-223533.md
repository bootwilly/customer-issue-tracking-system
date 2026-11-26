# 正式站登入失敗：PDO native prepares 讀回錯位資料  (2026-11-24 22:35:34)

症狀：admin 輸入正確 8 位數字仍顯示「帳號或密碼錯誤」。

診斷：
- 資料庫（MCP SELECT）正常：41 表、admin active、密碼雜湊長度 60，且與 install.sql 一致。
- 在 AuthService 暫時加 LOGIN_DIAG log（只記形狀不記值）：輸入是 8 個半形數字，但 $user 每個字串欄位長度都是 0。
- 一次性探測腳本（token 保護、會自我刪除）直接用 PDO 比較：
  - ATTR_EMULATE_PREPARES=false：id 為二進位、account/status 為空 → 讀回錯位
  - ATTR_EMULATE_PREPARES=true：1 / admin / 60 / active → 正常
  - 主機 PHP 8.5.6，PDO client 10.5.5，伺服器 MariaDB 11.4.10-cll-lve

修改：
- cits/config/database.php：mariadb options 加 `\PDO::ATTR_EMULATE_PREPARES => (bool) env('DB_EMULATE_PREPARES', true)`。
- cits/app/Services/AuthService.php：加入後再移除 LOGIN_DIAG（已還原為原本內容）。
- 兩個檔案都已上傳到 webdav-app；探測腳本已確認刪除（404）。測試 73/73 通過。
