# 會議紀錄系統（2026-11-26）

需求：內部／客戶會議紀錄、可給客戶看、可排定未來會議；決策 D1–D5、E1–E7 照建議（docs/meeting-system.md）。

## 新增
- cits/database/migrations/2026_11_29_000100_create_meetings_tables.php：meetings、meeting_attendees、meeting_items、meeting_comments、meeting_reads
- cits/app/Models/Meeting.php、MeetingAttendee.php、MeetingItem.php、MeetingComment.php
- cits/app/Repositories/MeetingRepository.php
- cits/app/Services/Meeting/MeetingService.php：可見範圍、編輯權限、狀態、討論事項、下次會議、留言、.ics、回收筒
- cits/app/Http/Controllers/Meeting/MeetingController.php、cits/routes/modules/meeting.php
- cits/resources/views/meetings/{index,form,show,_item,_comments,_comment}.blade.php
- cits/resources/js/meeting/items.js：討論事項自動存檔、Enter 新增、排序
- cits/tests/Feature/MeetingTest.php（9 個情境）
- docs/meeting-system.md

## 修改
- cits/database/seeders/data/workflow.php：statuses/transitions（meeting、meeting_item）、lookups（meeting_type、meeting_initial、meeting_item_initial、lookup_editable、event_label）、角色權限 meeting.*
- cits/app/Repositories/LookupAdminRepository.php：USAGE 加 meeting_type
- cits/app/Repositories/EventRepository.php：recentExists()
- cits/app/Repositories/AttachmentRepository.php、cits/app/Services/AttachmentService.php：附件 owner meeting / meeting_internal 的權限
- cits/app/Services/Pm/PmQueryService.php、cits/resources/views/issues/show.blade.php：問題單頁「會議紀錄」＋「排入會議」
- cits/app/Http/Controllers/ProjectAdmin/ProjectAdminController.php、cits/routes/modules/project_admin.php、cits/resources/views/project-admin/trash.blade.php、cits/app/Services/ProjectAdminService.php：會議回收筒、事件紀錄
- cits/resources/views/layouts/app.blade.php：頂列「流程圖」後加「會議」
- cits/vite.config.js：加入 resources/js/meeting/items.js

## 正式站
- 需先執行 deploy/out/upgrade-20261126-0001.sql

## 補丁（同日）
- 正式站 workflow_statuses / workflow_transitions 的 scope 為 varchar(10)，sql_mode 空白導致 meeting_item 被截成 meeting_it。
- 新增 cits/database/migrations/2026_11_29_000200_widen_workflow_scope.php：scope 放寬為 varchar(30)，並修正被截斷的資料。
- 新增 cits/tests/Feature/SeedDataLengthTest.php：用 MariaDB DDL 檢查 workflow.php 種子資料長度。
- 正式站需執行 deploy/out/upgrade-20261126-0007.sql。

## 從問題管理加入（同日）
- MeetingService：canPickIssues / issueStages / searchIssues / addIssues；addIssue 改用 addIssues（同一會議不重複加入）。
- MeetingRepository：linkedIssueIds / searchIssues / issuesByIds。
- MeetingController + routes：GET /meetings/{id}/issues、POST /meetings/{id}/items/issues。
- views/meetings/show.blade.php：「＋ 從問題管理加入」按鈕；resources/js/meeting/items.js：勾選對話框。
- tests/Feature/MeetingTest.php：新增情境，調整重複排入的預期。

## 問題管理列表多選排入會議（同日）
- PmController::index 傳 meetingTargets / canCreateMeeting；views/pm/index.blade.php 加勾選欄與「排入會議」操作列。
- MeetingController::bulkAddIssues（POST /meetings/add-issues）：排入現有會議，或「＋ 新會議…」帶到新增會議頁。
- MeetingService::prefillIssues + create() 支援 issue_ids；views/meetings/form.blade.php 顯示帶入的問題單。
- tests/Feature/MeetingTest.php 新增情境。
