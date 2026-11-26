# 刪除未使用檔案  (2026-11-24 23:30:37)

使用者同意後刪除：
- cits/app/Database/TypedMariaDbConnection.php（目錄 app/Database 一併移除）
- cits/tests/Feature/TypedConnectionTest.php

無其他引用，測試 73/73。正式站上的 /app/Database/TypedMariaDbConnection.php 仍在（MCP 沒有刪除工具；沒有被引用，不影響執行），可用 cPanel 刪除。
