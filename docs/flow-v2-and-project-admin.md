# 流程圖 v2（狀態、草稿/發布、指定編輯者、刪除）＋ 專案管理員 規劃

狀態：已實作、尚未部署（2026-11-25）。決策 D1–D7 全部照建議。實作差異見 §9。

## 1. 版本問題：存 100 次 = 100 版？

現況：每按一次「儲存並退出」就 +1 版，版本號失去意義，歷史也被灌爆。

解法：**草稿與發布分開**（文件管制的做法）。

| 動作 | 結果 | 版本號 |
|---|---|---|
| 儲存（編輯器「儲存並退出」） | 覆寫這份流程圖唯一的「工作草稿」 | 不變 |
| 發布（生效） | 草稿 → 新版本快照（不可改），成為目前生效版；必填「變更說明」 | +1 |
| 捨棄草稿 | 草稿清掉，回到目前生效版 | 不變 |

- 存 100 次只是改同一份草稿 100 次；只有「發布」才產生 v1、v2、v3。
- 草稿也有樂觀鎖（`draft_rev` 計數），兩人同時改草稿，後存的被擋。
- 誤存保險（可選）：保留最近 N 次草稿快照（setting `flow.draft_keep`，預設 10，超過自動刪最舊），編輯器可「還原到某次儲存」。不算版本、不給一般成員看。
- 一般成員（只有 `flow.view`）**只看得到生效版**，看不到草稿。
- 版本紀錄只列發布的版本，每版顯示：版號、發布人、時間、變更說明。

## 2. 流程圖狀態

| 狀態 | 意義 | 一般成員 | 有編輯權的人 |
|---|---|---|---|
| 草稿 | 從未發布過 | 看不到 | 看得到、可編 |
| 生效 | 有生效版本；可同時有「草稿修改中」 | 看生效版 | 看生效版＋草稿 |
| 失效 | 已停用的流程 | 預設看不到（見決策 D3） | 看得到；可「重新生效」 |

- 狀態碼、名稱、排序來自 lookup `flow_status`（draft / effective / obsolete）；「一般成員看得到哪些狀態」來自 lookup `flow_status_viewable`，不寫死。
- 轉換：草稿 →（發布）→ 生效 →（失效）→ 失效 →（重新生效）→ 生效。生效中也可再發布新版。
- 取代現有 `is_active`：上線時 `is_active=1` → 生效、`=0` → 失效，`published_version = version`。正式站現有 1 份（drawio，v2，啟用中）會變成「生效 v2」。

## 3. 誰能編輯（指定人員）

新表 `project_flow_editors (flow_id, user_id)`。

權限拆三種：

| 權限 | 誰有 | 能做 |
|---|---|---|
| `flow.view` | 全部專案角色（現況） | 看生效版、下載 |
| 編輯草稿 | 見下方規則 | 改草稿、捨棄草稿 |
| `flow.publish`（新） | client_pm、internal_pm、project_admin | 發布、失效、重新生效、設定編輯者 |
| `flow.delete`（新） | project_admin | 刪除流程圖、還原、清空回收筒 |

編輯草稿的規則（建議）：

```
canEditDraft(user, flow) =
    user 有 flow.publish
    OR user 在 project_flow_editors（必須是專案成員）
```

- 建立者自動加入編輯者。
- 被指定的人可以是任何專案成員（含 DEV、Test、客戶 View），他們只能改草稿，不能發布，發布要 PM 確認 → 天然的「提出修改 → PM 審核發布」流程。
- 移出專案時自動移除編輯者資格（查詢時 join project_members，不靠清資料）。
- 另一種做法見決策 D2（限定模式：指定後連 PM 也不能改）。

## 4. 刪除

| 對象 | 做法 |
|---|---|
| 流程圖 | 軟刪除（`deleted_at`、`deleted_by`），進「回收筒」，專案管理員可還原 |
| 清空回收筒 | 專案管理員手動按「永久刪除」→ 真刪 flow + revisions + editors + 草稿快照（二次確認，輸入流程圖標題） |
| 單一舊版本 | 不提供（版本是稽核紀錄）；要清就刪整份 |
| 草稿 | 「捨棄草稿」即可 |

- 每個動作寫 `activity_events`（flow_deleted / flow_restored / flow_purged），永久刪除後事件仍保留標題與版本數。
- 沒有排程自動清（cron 尚未確認可用）。

## 5. 專案管理員（project_admin）

定位：**只管單一專案的系統管理員**，內部人員專用的 spatie team role（team = project_id），和現有角色可複選（例如同時是內部 PM + 專案管理員）。

### 5.1 權限

| 權限碼（新） | 能做 |
|---|---|
| `project.members` | 專案成員：加入既有使用者、移出、勾選角色 |
| `project.trash` | 回收筒（流程圖還原／永久刪除；之後其他模組也掛這裡） |
| `flow.publish`、`flow.delete` | 見 §3、§4 |
| 其他 | 依決策 D5：是否自動帶內部 PM 的全部權限 |

### 5.2 邊界（防越權）

- 只能加入**已存在且啟用**的帳號；建立新帳號、停用帳號、重設密碼仍是系統管理員（決策 D4 可放寬成「可建本專案的客戶帳號」）。
- 不能指派／移除 `project_admin` 角色（只有系統管理員能指派），避免互相升權。
- 不能移出自己、不能改自己的角色。
- 每個專案至少保留 1 位專案管理員的檢查只在系統管理員端做（因為專案管理員本身不能動這個角色）。
- 所有成員異動寫事件（member_added / member_removed / member_roles_changed），專案管理頁可查。
- `Gate::before` 系統管理員全通過的現況不變。

### 5.3 畫面

- 選單新增「專案管理」（有 `project.members` 或 `project.trash` 才顯示），只作用在目前選的專案：
  - 成員：列表、加入（從啟用中且不在專案的帳號挑）、角色勾選（不含 project_admin）、移出。
  - 回收筒：已刪除的流程圖，還原／永久刪除。
  - 異動紀錄：這個專案的成員與刪除事件。
- 程式沿用 `AdminService::addMember / setMemberRoles / removeMember`，外面包一層 `ProjectAdminService` 加上邊界檢查（不碰 DB，DB 仍走 ProjectRepository）。

### 5.4 「刪除相關資訊」的範圍

第一階段只做：成員移出、流程圖刪除。

客戶問題單、內部問題單**不建議做刪除**（結算、時序、關聯單都靠它，刪了報表會對不上）。建議用既有的「取消／不處理」狀態作廢；真的要刪測試資料，另外規劃「測試資料清除」給系統管理員（決策 D6）。

## 6. 決策點（括號內為建議）

| # | 問題 | 建議 |
|---|---|---|
| D1 | 發布要不要審核（編輯者送審 → PM 核准）？ | 不另做送審；被指定的編輯者本來就不能發布，等於 PM 把關 |
| D2 | 指定編輯者是「額外加人」還是「限定只有這些人」？ | 額外加人（PM 一律可編） |
| D3 | 失效的流程圖一般成員看得到嗎？ | 列表加「已失效」篩選，可看但頁首紅字標示「已失效」 |
| D4 | 專案管理員可以建立新帳號嗎？ | 不行，只能從既有帳號加入 |
| D5 | 專案管理員自動擁有內部 PM 全部功能嗎？ | 否，純管理權限；要做 PM 工作就另外勾 internal_pm |
| D6 | 問題單要不要有刪除？ | 不做刪除，用作廢狀態 |
| D7 | 草稿快照保留次數 | 10 次（setting 可調） |

## 7. 資料異動（實作時產生 SQL，由使用者執行）

- `project_flows` 加：`status`、`published_version`、`draft_source`、`draft_title`、`draft_search_text`、`draft_rev`、`draft_base_version`、`draft_updated_by`、`draft_updated_at`、`deleted_at`、`deleted_by`；保留 `is_active`（不再使用，之後再移除）。
- `project_flow_revisions` 加：`note`（變更說明）。
- 新表：`project_flow_editors`、`project_flow_draft_snapshots`（D7）。
- lookup：`flow_status`、`flow_status_viewable`。
- setting：`flow.draft_keep`。
- 權限：`flow.publish`、`flow.delete`、`project.members`、`project.trash`；角色 `project_admin`；`flow.publish` 授權給 client_pm、internal_pm。
- 資料轉換：`UPDATE project_flows SET status = IF(is_active, 'effective', 'obsolete'), published_version = version`（由使用者執行）。

## 8. 工作量（估）

| 項目 | 天 |
|---|---|
| 草稿／發布／狀態／變更說明／草稿快照 | 1.5 |
| 指定編輯者 | 0.5 |
| 軟刪除／回收筒 | 0.5 |
| 專案管理員角色、專案管理頁（成員、回收筒、異動紀錄） | 1.5 |
| 測試、SQL、部署 | 0.5 |
| 合計 | 約 4.5 |

## 9. 實作紀錄（2026-11-25）

與規劃的差異：

- 沒有新增 `flow.publish`：沿用既有的 `flow.manage` 當「PM 權限」（建立、發布、狀態、指定編輯者、排序），少一次權限搬移。新增的是 `flow.delete`、`project.members`、`project.trash`。
- 狀態走既有的 workflow 引擎：`workflow_statuses` scope `flow`（draft／effective／obsolete，obsolete 為 terminal = 不能編輯）、`workflow_transitions`（draft→effective 需 `note`、effective⇄obsolete，權限 `flow.manage`）。狀態按鈕由 transitions 產生；草稿只能透過「發布」生效。初始狀態來自 lookup `flow_initial`。
- 狀態徽章顏色借用 `workflow_statuses.client_tab`（pending／closed／cancelled），所有用到 client_tab 的程式都限定 scope ticket，不受影響。
- 沒有 `published_version` 欄位：`version` 就是最新發布版號（0 = 從未發布），`title`／`source_md`／`search_text` 是發布版內容，草稿放在 `draft_*`。
- 專案管理員不能管理「持有 role_admin_only 角色的成員」（包含其他專案管理員），也不能動自己；只有系統管理員能指派 project_admin。
- 異動紀錄的動作名稱來自 lookup `event_label`。
- 一般成員不會看到「草稿修改中」標記（只有 PM 和被指定的編輯者看得到）。

檔案：

- migration `2026_11_28_000100_flow_drafts_status_editors.php`（含 `is_active` → status 的資料轉換 UPDATE）
- Model：`ProjectFlowEditor`、`ProjectFlowDraftSnapshot`；`ProjectFlow` 加 `hasDraft()`、`isPublished()`
- `FlowRepository`（重寫）、`FlowService`（重寫）、`ProjectAdminService`（新）、`EventRepository::forProject()`
- `FlowController`（重寫）、`ProjectAdminController`（新）、`routes/modules/flow.php`、`routes/modules/project_admin.php`
- View：`flows/index`、`flows/show`（重寫）、`flows/preview`、`flows/_upload`、`flows/editor`；`project-admin/{_tabs,members,trash,events}`
- `layouts/app.blade.php`：流程圖移到最前面、新增「專案管理」
- `deploy/make_upgrade_sql.php`：補 workflow_statuses／transitions、roles（NOT EXISTS）
- 測試：`FlowTest`（23）、`ProjectAdminTest`（4）；全套 164/164

升級 SQL：`deploy/out/upgrade-20261125-1446.sql`。部署後要清權限快取與 workflow 狀態快取（`cits.workflow_status_map`，5 分鐘）。
