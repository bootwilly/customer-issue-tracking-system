# 流程圖 v2（草稿／發布、狀態、指定編輯者、刪除）＋ 專案管理員

時間：2026-11-25 22:15–22:50（Asia/Taipei）

依據：`docs/flow-v2-and-project-admin.md`，決策 D1–D7 照建議。

## 新增

- `cits/database/migrations/2026_11_28_000100_flow_drafts_status_editors.php`
- `cits/app/Models/ProjectFlowEditor.php`、`ProjectFlowDraftSnapshot.php`
- `cits/app/Services/ProjectAdminService.php`
- `cits/app/Http/Controllers/ProjectAdmin/ProjectAdminController.php`
- `cits/routes/modules/project_admin.php`
- `cits/resources/views/project-admin/_tabs.blade.php`、`members.blade.php`、`trash.blade.php`、`events.blade.php`
- `cits/tests/Feature/ProjectAdminTest.php`

## 修改

- `cits/app/Models/ProjectFlow.php`：casts、`draftEditor()`、`deleter()`、`hasDraft()`、`isPublished()`
- `cits/app/Repositories/FlowRepository.php`：重寫（狀態篩選、軟刪除、編輯者、草稿快照、永久刪除）
- `cits/app/Repositories/EventRepository.php`：`forProject()`
- `cits/app/Services/Flow/FlowService.php`：重寫（草稿儲存不升版、發布、捨棄、還原快照、狀態變更、指定編輯者、刪除／回收筒）
- `cits/app/Http/Controllers/Flow/FlowController.php`：重寫
- `cits/routes/modules/flow.php`：publish、discard、status、editors、snapshots.restore、destroy；移除 toggle
- `cits/resources/views/flows/index.blade.php`、`show.blade.php`（重寫）、`preview.blade.php`、`_upload.blade.php`、`editor.blade.php`
- `cits/resources/views/layouts/app.blade.php`：流程圖移到客戶問題左邊；新增「專案管理」
- `cits/database/seeders/data/workflow.php`：scope `flow` 狀態與轉換、lookup `flow_initial`／`flow_status_viewable`／`role_admin_only`／`event_label`、setting `flow.draft_keep`、角色 `project_admin`
- `deploy/make_upgrade_sql.php`：輸出 workflow_statuses、workflow_transitions、roles
- `cits/tests/Feature/FlowTest.php`：改寫為草稿／發布模式
- `docs/flow-v2-and-project-admin.md`：狀態與 §9 實作紀錄

## 驗證

- Pest 全套 164/164。
- 本機瀏覽器：編輯器存檔只更新草稿（v1 不變）、檢視草稿、填變更說明發布成 v2、專案管理成員頁與異動紀錄。

## 產出

- `deploy/out/upgrade-20261125-1446.sql`（使用者執行）
