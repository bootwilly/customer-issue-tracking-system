# 管理員可瀏覽客戶問題頁面  (2026-11-25)

需求：管理員權限也要能看到客戶問題的頁面。

修改：
- cits/resources/views/layouts/app.blade.php：「客戶問題」導覽條件改為 `($u->isClient() || $u->is_admin) && can('ticket.view')`。
- cits/app/Http/Controllers/Client/TicketController.php：
  - canCreate 加上 `$u->isClient()`（admin 看不到「開立新單」）。
  - create / store 加上 `abort_unless(isClient(), 403)`（TicketService::create 本來就只允許客戶）。
  - show：只有客戶帳號才呼叫 markRead，admin 瀏覽不會出現在客戶看到的已讀名單。
- 編輯、取消、結案、重開、留言本來就由 TicketService::abilities 限定 isClient，admin 是唯讀。
- cits/tests/Feature/ClientPagesTest.php：新增 admin 唯讀瀏覽測試。測試 75/75，已部署。
