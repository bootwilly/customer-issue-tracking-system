# 已知問題修正（2026-11-26）

- app/Services/AuthService.php：新增 landingRoute()；app/Http/Controllers/Auth/HomeController.php 改用它（只有專案管理員角色不再 403）。
- database/seeders/data/workflow.php：dev_doing→test_pending 必填加 assignee_test_id；新增 lookup role_label。
- database/migrations/2026_11_30_000100_require_tester_on_dev_handoff.php：更新既有轉換的 requires_fields。
- app/Services/IssueService.php：KEPT_ASSIGNEES / ASSIGNEE_PERMISSION；abilities 隱藏已有的負責人欄位；transition 沿用既有負責人並檢查被指派者權限；chainLock 在客戶持有編輯鎖時拒絕派工。
- app/Services/Dev/DevService.php、app/Services/Test/TestService.php：建立者自動成為負責人。
- app/Services/TicketService.php：內部收件者通知連結改 /pm/tickets/{id}（pmUrl）。
- app/Services/Timeline/TimelineService.php：客戶單連結改 pm.tickets.show。
- app/Http/Controllers/Client/TicketController.php：show 對非客戶身分導向 PM 客戶單頁。
- resources/views/dev/_table.blade.php、issues/show.blade.php：來源顯示 lookup 名稱。
- resources/views/project-admin/members.blade.php、admin/projects/members.blade.php：角色顯示 role_label 名稱。
- tests/Feature/CoreFlowTest.php：送測帶 assignee_test_id；「客戶編輯中派工」改為預期拒絕。
- tests/Feature/BugfixTest.php：新增 5 個回歸測試。
- deploy/build.py：ignore_build_files，修正 config/services.php 被排除。
- HANDOVER.md、docs/manual/04、05、06、08、10：同步新行為。
