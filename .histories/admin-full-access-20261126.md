# 系統管理員可使用所有功能（2026-11-26）

需求：勾選「系統管理員」(users.is_admin) 的帳號要能用所有功能。
權限本身原本就由 Gate::before 全放行，擋住管理員的是寫死的 user_type 判斷。

- app/Models/User.php：新增 seesInternal()（is_admin 或內部帳號）、actsAsClient()（is_admin 或客戶帳號）。
- 內部端入口改用 seesInternal()：Pm/Concerns/GuardsInternal、Dev/Mes/Report/Test/TimelineService、IssueService（get/locked/comment）、
  ManualService、RevisionDiffService、AttachmentService、MeetingService（可見性/內部備註/內部留言/挑問題單）、MeetingController、
  layouts/app.blade.php 導覽、meetings/form、meetings/index。
- 客戶專屬動作改用 actsAsClient()：TicketController create/store/canCreate、TicketService create 與 abilities(edit/cancel/comment/close/reopen)；
  accept/reply/settle 改用 seesInternal()。addComment：管理員 reply 或 comment 任一可用即可留言。
- ProjectAdminService::protectedRoles/assignableRoles 收 ?User：系統管理員在「專案管理」也能指派/調整保護角色；ProjectAdminController 傳入 user。
- 保留 isClient() 的地方（資料語意，非權限）：首頁導向、客戶單已讀回條、Meeting::isClientOrigin、客戶建立會議強制開放、留言通知對象、客戶標籤顯示。
- tests/Feature/ClientPagesTest.php：舊「管理員唯讀」測試改為可開單/編輯/受理、仍不留已讀；新增 client 型管理員可進內部各頁測試。

## 手冊補充（2026-11-26 11:0x）
- docs/manual/09-system-admin.md：開頭改為「客戶端與內部端的功能都能使用」；新增「9.0 管理員可使用的功能」表格與注意事項（開單署名、不留已讀、非成員不在選單）。
- docs/manual/01-getting-started.md：選單表「客戶問題」系統管理員欄由「✔（唯讀）」改為「✔」。
- docs/manual/dist/manual.html 重建（PDF 未重建）。

## 部署（2026-11-26 11:25）
- 從凍結快照打包上傳，詳見 SESSION_LOG。
