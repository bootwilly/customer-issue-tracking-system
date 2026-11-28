# 內部單結案功能規劃：PM結案、會議討論結案（含收尾流程）

> 給開發者（Codex）的實作規格。撰寫：2026-11-28。目前狀態：只有規劃，尚未實作。
> 開工前先讀 `AGENTS.md`、`PROJECT_CONTEXT.md`、`SESSION_LOG.md`，並遵守裡面的分層與禁止事項。

---

## 0. 需求原文

1. 在問題管理的「狀態變更」，PM 可以直接結案，選項名稱是 **「PM結案」**。
2. 會議討論後，可以在會議的討論事項裡直接結案，名稱是 **「會議討論結案」**。
3. 兩種結案都**不能讓正在進行的工作直接消失**。目前負責的人（DEV、Test、上線負責人）要先完成收尾，單才算結案。

---

## 1. 專案既有架構（必讀）

| 規則 | 說明 |
|---|---|
| 分層 | Controller → Service（BLL）→ Repository。**Service 不可直接操作 DB**，不可寫 SQL、不可用 `DB::` 或 Model 查詢。這些都放 Repository |
| 不寫死業務值 | 狀態、轉換、權限、lookup 一律寫在 `cits/database/seeders/data/workflow.php`，程式透過 `WorkflowService` 讀取（`status()`、`lookupCodes()`、`allowedTargets()`、`assertTransition()`、`can()`） |
| 交易 | 寫入一律包在 `UnitOfWork::run()`，要更新的資料先 `lockForUpdate` |
| 事件 | `EventRepository::log($pid, 'issue', $id, $type, $from, $to, $actorId, $payload)`。顯示名稱放在 lookup `event_label` |
| 通知 | `WatchService::notifyIssue()`、`notifyTicket()`；依權限找人：`EventRepository::userIdsWithPermission()` |
| 客戶單連動 | 內部單狀態改變後呼叫 `SettlementService::recompute($issueId, $actorId)` |
| 相依 | `DependencyService::doneStatuses()` 決定上游單「不再擋人」的狀態 |
| 回收筒 | Issue 用 SoftDeletes。直接下 SQL 的 `DB::table('issues')` 查詢都要加 `whereNull('deleted_at')`。永久刪除在 `IssueRepository::purge()`，**新增任何帶 issue_id 的資料表，都要在 purge 一起刪** |
| 正式站 DB | AI 不能改正式站資料庫。schema 或資料變更做成 SQL 檔，**由使用者執行**（見第 11 節） |
| 文件 | 改到的功能要同步更新 `docs/manual/*.md`；`.histories/<name>-<timestamp>.md` 要記錄修改內容 |
| 測試 | Pest：`cd cits && php artisan test`，全部要通過。新寫的程式碼要通過 `vendor/bin/pint --test <files>` |

現有內部單狀態（`workflow.php` → `statuses`，scope `issue`）：

```
new 新建(PM) → discussing 待討論(PM) → dev_todo 待開發(DEV) → dev_doing 開發中(DEV)
→ test_pending 待測試(TEST) → test_scheduled 排程測試(TEST) → test_doing 測試中(TEST)
→ release_pending 測試通過待更新正式環境(RELEASE) → released 正式環境已更新(terminal, released)
另有：test_found 新發現(DEV)、test_failed 仍有異常(DEV)、cancelled 取消(terminal, excluded)、wont_fix 不處理(terminal, excluded)
```

---

## 2. 設計總覽

```
                    ┌─────────── 不需要收尾（沒有人在做）───────────┐
                    │                                              ▼
任何未結束的狀態 ──[PM結案 / 會議討論結案]──► 需要收尾？──是──► closing 結案收尾中 ──全部收尾完成──► closed_pm PM結案
   （new…release_pending）                                         │                                 closed_meeting 會議討論結案
                                                                   └──[撤回結案]──► 回到原本的狀態
```

- 新增 3 個狀態：
  - `closing`：結案收尾中。非終態。
  - `closed_pm`：PM結案。終態。
  - `closed_meeting`：會議討論結案。終態。
- 選單上看到的選項名稱就是目標狀態名稱：狀態變更的下拉選單會出現「PM結案」，會議事項裡的按鈕叫「會議討論結案」。
- 送出結案時，系統依**目前狀態**和**目前指派的人**產生「收尾工作」（新資料表 `issue_close_tasks`）：
  - **有收尾工作**：單進入 `closing`，各負責人到自己的工作清單或內部單頁按「確認收尾」。最後一個人確認完，系統自動把單轉成 `closed_pm` 或 `closed_meeting`。
  - **沒有收尾工作**（例如 new、discussing，或根本沒有指派人）：直接進入結案狀態。
- 收尾期間 PM 可以「撤回結案」，單回到結案前的狀態，還沒做完的收尾工作作廢。

---

## 3. 資料結構

### 3.1 新 migration：`cits/database/migrations/2026_12_05_000100_add_issue_close.php`

**issues 加欄位**

| 欄位 | 型別 | 說明 |
|---|---|---|
| `close_target` | string(30) null | 收尾完成後要進入的狀態（`closed_pm` 或 `closed_meeting`）。只有在 `closing` 期間有值 |
| `close_from` | string(30) null | 進入 `closing` 前的狀態，撤回時用 |
| `close_reason` | string(1000) null | 結案原因。PM結案是使用者輸入；會議結案帶入事項的「結論」 |
| `close_meeting_item_id` | foreignId null → `meeting_items`，nullOnDelete | 會議討論結案的來源事項 |
| `closed_at` | dateTime null | 進入終態的時間 |
| `closed_by` | foreignId null → users | 送出結案的人 |

**新表 `issue_close_tasks`**（一張單一次結案可能有多筆收尾工作）

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | id | |
| `issue_id` | foreignId → issues | |
| `round` | unsignedInteger | 第幾次結案。撤回後再結案就加 1，舊的那一輪保留作紀錄 |
| `role` | string(20) | 收尾角色，值來自 lookup `issue_close_role`：`dev`、`test`、`release` |
| `user_id` | foreignId null → users | 指定的負責人。`release` 可為 null，表示任何有 `release.manage` 權限的人都可以收尾 |
| `status` | string(16) | `pending`、`done`、`void`（撤回時作廢） |
| `note` | string(1000) null | 收尾說明，**必填**，例如「分支已還原」「已從更新包移出」 |
| `done_by` | foreignId null → users | |
| `done_at` | dateTime null | |
| timestamps | | |
| index | (`issue_id`, `round`, `status`)、(`user_id`, `status`) | |

> ⚠️ 一定要把 `issue_close_tasks` 加進 `IssueRepository::purge()`，否則永久刪除內部單時會撞外鍵。

### 3.2 Model

- 新增 `App\Models\IssueCloseTask`：belongsTo issue、user、doneBy。
- `Issue` 加 `closeTasks()`，關聯 hasMany；`casts` 補上 `closed_at`。

---

## 4. workflow.php 設定

### 4.1 statuses

格式：scope, code, name, stage, client_tab, terminal, excluded, released, sort。

```php
['issue', 'closing', '結案收尾中', 'CLOSE', null, false, false, false, 115],
['issue', 'closed_pm', 'PM結案', null, null, true, false, false, 140],
['issue', 'closed_meeting', '會議討論結案', null, null, true, false, false, 150],
```

- 結案狀態是 **terminal**，但**不是 excluded**（不是取消），也**不是 released**（沒有上線）。報表的「已上線」數字不會被影響。
- `closing` 的 stage 用 `CLOSE`。如果 `stage_role` 或畫面的階段篩選會用到 stage，請一併確認，必要時在 lookup `stage_role` 補上。

### 4.2 transitions

`assertTransition` 會檢查轉換表，所以每一條路都要列出來。

```php
// PM結案：每個未結束的狀態 → closed_pm（UI 的目標；service 判斷要不要先進 closing）
foreach (['new','discussing','test_found','dev_todo','dev_doing','test_failed','test_pending','test_scheduled','test_doing','release_pending'] as $s)
    ['issue', $s, 'closed_pm', 'issue.close', ['close_reason']],
// 會議討論結案：同樣的來源狀態 → closed_meeting，只能從會議觸發（system），所以不會出現在狀態下拉
    ['issue', $s, 'closed_meeting', 'system', null],
// 收尾期間
['issue', 'closing', 'closed_pm', 'system', null],
['issue', 'closing', 'closed_meeting', 'system', null],
// 撤回：closing → 原本的狀態（每個來源狀態各一條，system）
    ['issue', 'closing', $s, 'system', null],
```

- `workflow.php` 是純資料陣列，不能寫 foreach。上面只是示意，請**展開成一行一行**的資料列。
- 為什麼 `closed_meeting` 用 `system`：`WorkflowService::allowedTargets()` 只列出使用者有權限的轉換，`system` 權限永遠不成立，所以內部單頁的下拉不會出現「會議討論結案」。會議端由 service 直接檢查權限後執行（見 5.4）。
- **service 必須另外驗證** `closing → 原本狀態` 的目標等於 `issues.close_from`，不能讓人撤回到任意狀態。

### 4.3 lookups

```php
// 結案後對客戶單、相依關係都算「已解決」的狀態
['issue_resolved', 'closed_pm', 'closed_pm', 10],
['issue_resolved', 'closed_meeting', 'closed_meeting', 20],
// 收尾角色：code = issue_close_tasks.role，name = 畫面標籤
['issue_close_role', 'dev', 'DEV 收尾', 10],
['issue_close_role', 'test', 'Test 收尾', 20],
['issue_close_role', 'release', '上線收尾', 30],
// 收尾規則：code = 來源狀態，name = 需要的角色（逗號分隔）
['issue_close_wrapup', 'test_found', 'dev', 10],
['issue_close_wrapup', 'dev_todo', 'dev', 20],
['issue_close_wrapup', 'dev_doing', 'dev', 30],
['issue_close_wrapup', 'test_failed', 'dev', 40],
['issue_close_wrapup', 'test_pending', 'dev,test', 50],
['issue_close_wrapup', 'test_scheduled', 'dev,test', 60],
['issue_close_wrapup', 'test_doing', 'dev,test', 70],
['issue_close_wrapup', 'release_pending', 'test,release', 80],
// 事件名稱
['event_label', 'close_requested', '送出結案（收尾中）', 360],
['event_label', 'close_task_done', '完成收尾', 370],
['event_label', 'close_completed', '結案完成', 380],
['event_label', 'close_withdrawn', '撤回結案', 390],
['event_label', 'closed_by_meeting', '會議討論結案', 400],
```

- 不在 `issue_close_wrapup` 裡的狀態（`new`、`discussing`）不需要收尾，直接結案。
- 角色要指派給誰：
  - `dev` → `assignee_dev_id`
  - `test` → `assignee_test_id`
  - `release` → `user_id = null`，有 `release.manage` 權限的人都可以收尾
- 該欄位是 null（沒有指派人）時，**這個角色就不產生收尾工作**，不要卡住流程。
- 注意 `SeedDataLengthTest` 會檢查欄位長度：lookup name 最多 100 字元、code 30 字元。

### 4.4 權限

- 新增 `issue.close`，加到 `roles.internal_pm`。
- 會議討論結案也要求 `issue.close`。會議裡沒有這個權限的人看不到按鈕。
  - 要不要也開給主持會議的其它角色，**請使用者決定**；預設只有內部 PM。

---

## 5. 後端實作

### 5.1 新 Repository：`App\Repositories\IssueCloseRepository`

方法：

- `createTasks(int $issueId, int $round, array $rows)`
- `pending(int $issueId, int $round): Collection`
- `lockTask(int $taskId): ?IssueCloseTask`
- `markDone(IssueCloseTask $t, int $userId, string $note)`
- `voidRound(int $issueId, int $round)`
- `maxRound(int $issueId): int`
- `pendingForUser(int $projectId, int $userId, bool $release): Collection`：工作清單用，要 join issues 並加 `whereNull('issues.deleted_at')`
- `ofIssue(int $issueId): Collection`：內部單頁顯示用，包含歷史輪次

### 5.2 新 Service：`App\Services\IssueCloseService`

依賴：IssueRepository、IssueCloseRepository、TicketRepository、EventRepository、WorkflowService、SettlementService、WatchService、UnitOfWork，以及 IssueService 或拆出的套件服務（處理更新包，見下方）。

**request**

```php
/** PM結案 / 會議討論結案的共同入口。回傳更新後的 Issue。 */
public function request(User $u, int $pid, int $issueId, string $target, string $reason, ?string $clientNote, ?int $meetingItemId = null): Issue
```

流程，全部在同一個交易裡：

1. `IssueRepository::findForUpdate()` 鎖住這張單，並檢查專案和內部人員身分。
2. 檢查 `$target` 是 `issue_resolved` 裡的值。
   - `closed_pm`：用 `wf->assertTransition('issue', $from, 'closed_pm', $u, $pid, ['close_reason' => $reason])`。
   - `closed_meeting`：用 `wf->can($u, 'issue.close', $pid)`，再確認 `transitionsFrom($from)` 裡有 `closed_meeting` 這條。
3. `$reason` 不能是空白。有連結客戶單時，`$clientNote` 必填，訊息照抄現有的「此內部單有連結客戶單，請填寫給客戶看的說明。」，存到 `issues.client_note`。
4. 依 `issue_close_wrapup[$from]` 和目前指派的人，算出這次要產生的收尾工作。
5. **沒有收尾工作**：直接呼叫 `complete($u, $issue, $target)`，見下面 complete。
6. **有收尾工作**：
   - 更新 issues：`status=closing`、`stage=CLOSE`、`close_target`、`close_from`、`close_reason`、`close_meeting_item_id`、`closed_by=$u->id`。
   - round 用 `maxRound + 1`，建立收尾工作。
   - 記事件 `close_requested`，payload 帶 target、from、reason、各角色名稱。
   - 通知收尾負責人，標題例如「{issue_no} 已送出結案，請完成收尾」。`release` 角色通知所有有 `release.manage` 的人。
   - 處理還在審核中的延期申請（`due_change_requests.status = pending`）：自動駁回，`reviewed_by = $u`，原因填「內部單結案」。這要經過 Repository。
7. 呼叫 `SettlementService::recompute()`。
8. 若有 `$meetingItemId`，再記一筆 issue 事件 `closed_by_meeting`，payload 帶 meeting 標題、meeting_id、item_id。

**finishTask**

```php
public function finishTask(User $u, int $pid, int $taskId, string $note): Issue
```

- 鎖住這筆收尾工作和它的單。檢查：工作是 `pending`、這張單是 `closing`、同一個 round。
- 誰能按：
  - `user_id` 有值：只有那個人，或系統管理員。
  - `user_id` 是 null（release）：有 `release.manage` 的人。
- `note` 必填。標記完成，記事件 `close_task_done`，payload 帶 role、note。通知 `closed_by`（送出結案的 PM）。
- 如果這一輪的 pending 都清空了，就呼叫 `complete($u, $issue, $issue->close_target)`。

**withdraw**

```php
public function withdraw(User $u, int $pid, int $issueId): Issue
```

- 需要 `issue.close`，單必須是 `closing`。
- 把這一輪的 pending 工作作廢（`voidRound`）。狀態改回 `close_from`，stage 用 `wf->status('issue', close_from)->stage`。清空 `close_target`、`close_from`、`close_meeting_item_id`。
- 記事件 `close_withdrawn`，通知這一輪的負責人「結案已撤回，請繼續處理」。最後呼叫 `recompute`。

**complete**（private）

- 驗證轉換：`assertTransition('issue', $issue->status, $target, …)`。若目前是 `closing`，會走 `closing → closed_*`；直接結案則走 `$from → closed_*`。這兩條都已列在轉換表。
- `closed_meeting` 和 `closing → *` 是 `system` 權限，`assertTransition` 會擋下來，有兩個做法擇一：
  - (a) 在 WorkflowService 加 `assertSystemTransition()`：只檢查轉換存在，不檢查權限。
  - (b) 沿用 `TicketService` 或 `SettlementService` 現有的 system 轉換做法。
  - **先看現有程式怎麼做，照同一套。**
- 更新 issues：`status=$target`、`stage=null`（`wf->status($target)->stage`）、`closed_at=now()`，清空 `close_target`、`close_from`。
- **更新包**：`package_id` 有值、而且那個更新包還沒上線時，把 `package_id` 清成 null，讓單離開更新包。
  - 請重用 `IssueService::removeFromPackage` 裡檢查「更新包是否已凍結」的邏輯（`assertPackageOpen`）。
  - 但**不要**走它的 system 轉換 `test_pending → dev_doing`，因為單已經要結案了。
  - 建議把「清 package_id + 檢查更新包未凍結」抽成 IssueService 的公開方法，讓兩邊共用。
- 記事件：`status`（from、to），以及 `close_completed`（只有經過 closing 時才記）。
- 呼叫 `watch->notifyIssue()` 通知關注者和指派人，再呼叫 `SettlementService::recompute()`。

### 5.3 客戶單連動：`SettlementService::recompute`

目前的規則：

- 有效單 = 不是 excluded 的單。
- 有效單全部 released → 客戶單轉成待結案。

要改成：

```php
$resolved = $this->wf->lookupCodes('issue_resolved');
if ($active->every(fn ($i) => $statuses[$i->status]->is_released || in_array($i->status, $resolved, true))) { ... pending_close ... }
```

- 結案的單算「已解決」。客戶單轉成待結案後，讓客戶確認結案；客戶有異議可以按現有的「提出異常」。
- 客戶單上會看到 `client_note`（給客戶的說明），請確認客戶端畫面有顯示，沿用 excluded 狀態的顯示方式。
- `pending_close` 事件 payload 的 `excluded` 清單邏輯不用改。

### 5.4 相依：`DependencyService::doneStatuses()`

改成 `is_released || is_excluded || in issue_resolved`：上游單結案後，下游不再被它擋住。

### 5.5 會議端

- `MeetingService` 新增 `closeIssue(User $u, int $pid, int $meetingId, int $itemId, ?string $clientNote): MeetingItem`：
  - 檢查會議可編輯，沿用 `editableMeeting`；事項要有 `issue_id`，而且單不在回收筒（用 `issueTrashed()`）。
  - 事項的「結論」不能是空白，訊息「請先填寫結論再結案」。
  - 單還不能是終態；如果已經是 `closing`，訊息「此內部單已在結案收尾中」。
  - 呼叫 `IssueCloseService::request($u, $pid, $issueId, 'closed_meeting', $item->conclusion, $clientNote, $item->id)`。
  - 事項狀態改成 `done`：用 `wf->assertTransition(meeting_item open → done)`，權限不足就跳過，不要整個失敗。接著呼叫 `itemChanged()`。
- 路由：`POST /meetings/{id}/items/{item}/close-issue` → `MeetingController::closeIssue`，回傳 JSON（沿用 `itemResult` / `itemJson`）。
- `MeetingController::itemJson` 加兩個欄位：
  - `can_close_issue`：內部人員、有 `issue.close`、有連結單、單不是終態、單不是 closing。
  - `issue_closing`：單是否在 closing。
- 現有的 `issue_closed` 徽章依 `is_terminal` 判斷，結案後會自動顯示，不用改。

---

## 6. Controller 與路由

| 方法 | 路由 | 說明 |
|---|---|---|
| `IssueController::transition` | 既有 `POST /issues/{id}/transition` | 當 `to` 是 `issue_resolved` 裡的值，改呼叫 `IssueCloseService::request()`。要新增驗證欄位 `close_reason`：nullable、string、max 1000 |
| `IssueController::finishCloseTask` | `POST /issues/{id}/close-tasks/{task}` | body：`note`（required、max 1000） |
| `IssueController::withdrawClose` | `POST /issues/{id}/close/withdraw` | |
| `MeetingController::closeIssue` | `POST /meetings/{id}/items/{item}/close-issue` | body：`client_note`（nullable、max 500） |

- `transitionInput()`（`PmQueryService`）只會保留目標狀態 `requires_fields` 裡列的欄位。`close_reason` 已經列在 `closed_pm` 的 requires 裡，會自然帶到。
- `client_note` 現在只在「排除」類的目標才會帶入，`closed_pm` 也要能帶。請修改 `transitionInput` 讓結案目標也保留 `client_note`。
- 錯誤一律丟 `BusinessException`；Controller 用 `act()`，或用 JSON 回傳 422。

---

## 7. 畫面

### 7.1 內部單頁 `resources/views/issues/show.blade.php`

- 狀態變更的選單會自動出現「PM結案」，來自 `allowedTargets`。展開後顯示：
  - 「結案原因」textarea，必填。
  - 有連結客戶單時，加上「給客戶的說明」。
  - 依 `issue_close_wrapup` 預告需要收尾的人：「送出後需要以下人員收尾：DEV ○○、Test ○○」。沒有收尾工作就顯示「沒有進行中的工作，送出後直接結案」。這段由 `PmQueryService::issuePage` 算好傳給畫面。
- 狀態是 `closing` 時，頁面上方顯示「結案收尾中」區塊：
  - 目標（PM結案／會議討論結案）、原因、來源會議連結（`close_meeting_item_id` → 會議頁）。
  - 收尾清單：角色、負責人、狀態、說明、完成時間。
  - 目前使用者可以收尾的那一筆，顯示 textarea「收尾說明」和「確認收尾」按鈕。
  - 有 `issue.close` 的人看到「撤回結案」按鈕，按下要先 confirm。
  - closing 期間，其它狀態變更選單要隱藏。轉換表本來就只有 system 的轉換，`allowedTargets` 會是空的。
- 已經結案的單，在「取消／不處理原因」旁邊顯示「結案原因」（`close_reason`），以及結案方式和時間。
- 時間軸的新事件，用 `IssueService::eventNote()` 補上說明，例如收尾說明、會議名稱。

### 7.2 DEV／Test 工作清單（`resources/views/dev/index.blade.php`、`test/index.blade.php`）

- 頂端新增「待收尾」區塊，只列出輪到自己的收尾工作（`pendingForUser`）。有 `release.manage` 的人也列出 `release` 角色的工作。
- 每筆顯示單號、標題、結案原因、連到內部單頁的連結，在內部單頁按收尾。
- 問題管理列表的 `closing` 狀態徽章會自動帶出名稱，不用另外處理；狀態篩選也會自動出現「結案收尾中」。

### 7.3 會議討論事項 `resources/views/meetings/_item.blade.php` + `resources/js/meeting/items.js`

- 事項有連結內部單、而且 `can_close_issue` 時，在單號旁顯示按鈕「會議討論結案」。
- 按下後：
  - 結論是空白 → 前端先提示「請先填寫結論」。
  - 單有連結客戶單 → 跳出小框，輸入「給客戶的說明」。
  - 確認框顯示預告的收尾人員，可在 itemJson 帶 `close_wrapup` 文字。
- 送出成功後，事項狀態變成已完成，單號旁顯示「結案收尾中」或「內部單已結案」。用 `applyItem()` 依 `issue_closing`、`issue_closed` 切換。
- 客戶端不顯示任何結案按鈕或內部資訊（`$isClient` 判斷）。

---

## 8. 邊界情況（都要有測試）

| 情況 | 處理 |
|---|---|
| `new`、`discussing` 直接 PM結案 | 不產生收尾工作，直接 `closed_pm` |
| `dev_doing` 有 DEV | 1 筆 dev 收尾；DEV 確認後才變 `closed_pm` |
| `test_doing` 有 DEV + Test | 2 筆收尾，兩人都確認才結案；最後一人按完時自動結案 |
| `release_pending` | 產生 test 收尾，加上 release 收尾（`user_id` null，`release.manage` 都能按） |
| 需要的角色沒有指派人 | 這個角色不產生收尾；全部都沒有就直接結案 |
| 收尾中再按一次 PM結案 | 擋下：轉換表沒有 `closing → closed_pm` 給人按，`allowedTargets` 是空的 |
| 撤回 | 狀態回到 `close_from`，pending 工作改成 void；之後再結案用新的 round，舊紀錄保留 |
| 非負責人按收尾 | 403 或 BusinessException |
| 單在更新包、包還沒上線 | 結案完成時清空 `package_id`，單離開更新包 |
| 單在已上線的更新包 | 不會發生：已上線的單是 released 終態，不能結案 |
| 有連結客戶單，但沒填給客戶的說明 | 擋下 |
| 客戶單的連結單全部已解決（released 或結案） | 客戶單轉成 `pending_close` |
| 下游單相依這張單 | 結案後下游不再被擋 |
| 會議事項沒有結論 | 擋下 |
| 會議事項的單在回收筒 | 擋下，沿用 `issueTrashed` |
| 會議事項的單已經是終態 | 不顯示按鈕；硬打 API 會被擋下 |
| 有 pending 的延期申請 | 送出結案時自動駁回 |
| 回收筒刪除 | 只有 `new` 能刪，結案的單不會進回收筒。永久刪除要清 `issue_close_tasks`（IssueRepository::purge） |
| 同時操作 | 結案、收尾、撤回都要 `lockForUpdate` 鎖住 issue；收尾時也鎖 task |

---

## 9. 報表／匯出／其它要檢查的地方

- `ReportRepository` 的狀態分布會自動出現新狀態；「已上線」只看 released，不受影響。請確認畫面上沒有寫死狀態列表。
- 匯出（`IssueExportService` 等）用 `wf->label()` 顯示狀態，應該會自動帶出名稱。可以考慮在匯出欄位 lookup 加 `close_reason`、`closed_at`，這是選做。
- `TimelineRepository`（時程總覽）的「未結束」清單：如果用 `is_terminal` 判斷，會自動排除結案的單；`closing` 照常顯示。
- MCP 查詢（`McpQueryRepository`）會自動帶出新狀態。
- `MeetingService::scheduleNext(skipClosed)` 依 `is_terminal` 判斷，結案的單排下次會議時會被略過，不用改。

---

## 10. 測試清單（`cits/tests/Feature/IssueCloseTest.php`）

1. PM 在 `new` 狀態按 PM結案 → 直接 `closed_pm`；`close_reason` 有存；時間軸有 `status` 事件。
2. `dev_doing` 按 PM結案 → `closing`，1 筆 dev 收尾；DEV 收尾後變 `closed_pm`；事件 `close_requested`、`close_task_done`、`close_completed` 都有。
3. `test_doing`（DEV + Test）→ 2 筆收尾；只有 DEV 完成時還是 closing，Test 也完成後才結案。
4. `release_pending` → release 收尾，有 `release.manage` 的人可以完成。
5. 非負責人收尾 → 被拒。
6. 撤回 → 回到原狀態，舊工作變 void；再結案時 round = 2。
7. 沒有 `issue.close` 的人（DEV、Test、客戶）→ 下拉沒有「PM結案」，硬打 API 會被擋。
8. 有連結客戶單、沒填 client_note → 被擋；填了之後結案 → 客戶單 `pending_close`。
9. 在未上線更新包裡的單結案 → `package_id` 被清空。
10. 下游相依：上游結案後，`blockers` 變成空的。
11. 會議討論結案：
    - 沒有結論 → 被擋。
    - 有結論 → 單變 `closed_meeting`，或在需要收尾時變 `closing` 且 `close_target=closed_meeting`。
    - 事項狀態變 done，`close_meeting_item_id` 有存，時間軸有 `closed_by_meeting`。
12. 會議 itemJson 的 `can_close_issue`、`issue_closing` 值正確；客戶端看不到這兩個欄位和按鈕。
13. pending 延期申請在結案送出時被駁回。
14. 永久刪除內部單（回收筒）時，`issue_close_tasks` 一起刪，不會撞外鍵。這要在 IssueTrashTest 補一個情境；只有 new 能刪，可以直接建一筆 task 資料來測。
15. `SeedDataLengthTest`、`ManualTest` 和其它既有測試全部通過。

---

## 11. 正式站升級 SQL（使用者執行）

1. 本機產生：

   ```
   php deploy/make_upgrade_sql.php --no-baseline --applied-file=<除了新 migration 以外所有 migration 名稱的清單>
   ```

2. 從產生的檔案**只挑出這次的內容**，另存成 `deploy/out/upgrade-issue-close-<日期>.sql`：
   - 新 migration 的 DDL，以及它的 migrations INSERT
   - 新的 `workflow_statuses`（3 筆）
   - 新的 `workflow_transitions`（全部新增的列）
   - 新的 `lookup_items`
   - `issue.delete` 之後新增的權限 `issue.close`，以及**只給 internal_pm 的那一筆** `role_has_permissions`
3. **不要**使用完整的 `role_has_permissions` INSERT IGNORE：它會把管理員手動拿掉的權限加回去。專案先前已決定只用精準的 SQL。
4. 交給使用者在 phpMyAdmin 執行，執行前先備份。
5. 使用者執行後，用一次性的唯讀檢查腳本確認（只有 SELECT，執行後自刪）。
6. **必須先跑 SQL 才能部署程式**：新程式會讀新欄位、新資料表。
7. 部署後要**清 spatie 權限快取**和 `cits.workflow_status_map` 快取，因為新增了權限和狀態。做法見 `docs/DEPLOYMENT_GUIDE.md` 附錄 C。

---

## 12. 文件

- `docs/manual/04-pm.md`：4.9 新增「PM結案」小節，說明收尾流程、撤回、給客戶的說明。
- `docs/manual/05-dev.md`、`06-test.md`：新增「待收尾」區塊的說明，以及怎麼確認收尾。
- `docs/manual/11-meetings.md`：新增「會議討論結案」，包括必須先填結論、誰可以按。
- `docs/manual/02-workflow.md`：狀態圖補上 closing、closed_pm、closed_meeting。
- `.histories/issue-close-<timestamp>.md`：修改紀錄。
- 完成後在 `SESSION_LOG.md` 追加一行 ASCII 紀錄；`PROJECT_CONTEXT.md` 補上「結案收尾」的長期規則。這兩個檔只能用 ASCII。

---

## 13. 驗收標準

- [ ] 內部 PM 在內部單頁的狀態變更看得到「PM結案」，DEV、Test、客戶看不到。
- [ ] 會議討論事項裡，有連結單、有 `issue.close` 的人看得到「會議討論結案」。
- [ ] 有人正在處理的單，結案後先進「結案收尾中」，各負責人在工作清單看得到「待收尾」。全部確認後自動變成 PM結案或會議討論結案。
- [ ] 收尾中可以撤回，單回到原狀態，紀錄都保留。
- [ ] 客戶單、相依、更新包、延期申請都照第 5、8 節處理。
- [ ] 全部測試通過；新程式碼通過 pint。
- [ ] 升級 SQL 是精準版本，使用者執行後先驗證，再部署程式、清快取。

---

## 14. 需要使用者決定的點（未決定前照預設做）

| # | 問題 | 預設 |
|---|---|---|
| 1 | 會議討論結案除了內部 PM，其它角色（例如主持會議的 DEV）能不能按？ | 只有有 `issue.close` 的人，也就是內部 PM |
| 2 | 結案後客戶單要怎麼處理？ | 算「已解決」，客戶單轉成待結案，讓客戶確認 |
| 3 | 收尾說明要不要必填？ | 必填，留下收尾紀錄 |
| 4 | `new`、`discussing` 的 PM結案，和現有的「取消」差在哪？ | 取消 = 不處理或作廢，對客戶單算排除；PM結案 = 問題已經處理或回答，對客戶單算已解決 |
| 5 | 已結案的單能不能重新開啟？ | 這次不做；需要的話另開需求 |
