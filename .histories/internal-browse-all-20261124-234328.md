# 內部帳號可瀏覽所有內部頁面  (2026-11-24)

需求：內部帳號一定可以瀏覽所有頁面，差別只在能不能新增或編輯。

規則：瀏覽 = 內部帳號 + issue.view（所有內部角色都有）；動作仍用各自權限（issue.edit_pm / issue.create / ticket.accept / dev.manage / test.manage / mes.manage / due.approve）。

修改：
- cits/resources/views/layouts/app.blade.php：問題管理、開發管理、測試管理、MES 頁面的導覽改為 `! isClient() && can('issue.view')`。
- cits/app/Http/Controllers/Pm/PmController.php：index guard issue.edit_pm → issue.view。
- cits/app/Http/Controllers/Pm/PmTicketController.php：show guard issue.edit_pm → issue.view（accept/ack/reply/settle/issue/link/quote 仍為 issue.edit_pm）。
- cits/app/Services/Pm/PmQueryService.php：ticketPage 新增 canAck（issue.edit_pm）。
- cits/resources/views/pm/ticket.blade.php：「確認已讀修訂」按鈕加上 @if ($canAck)。
- cits/app/Services/Mes/MesService.php：新增 canView（內部 + issue.view）。
- cits/app/Http/Controllers/Mes/MesController.php：index 改用 canView，並傳入 canManage；其他動作仍用 canManage。
- cits/resources/views/mes/index.blade.php：新增表單、批次匯入、每列的編輯/停用按鈕都包在 @if ($canManage) 裡。
- cits/tests/Feature/DevTestPagesTest.php：新增測試，dev/test/pm 都能瀏覽 6 個內部頁面，而 dev 看不到 PM 的動作、POST accept 回 403。
- 測試 74/74，已部署 8 個檔案。
