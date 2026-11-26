# 專案通知頻道規劃：LINE 群組、Discord、Telegram、Teams

> 給實作的 agent 用。2026-11-26 規劃，尚未開工。
> 開工前請先讀 `docs/PLATFORM_DEV_RECORD.md`、`PROJECT_CONTEXT.md`、`SESSION_LOG.md` 的最後幾段。
> 標「⚠ 待確認」的是外部平台的規格或費用，可能已經改變，實作前請以官方文件為準。

---

## 0. 目標與範圍

- 系統事件（目前會寄 Email 的那些）以**專案為單位**推送到聊天群組或頻道。
- 每個事件只推一則，不管收件人是誰，也**不處理個人通知**（個人通知繼續用 Email 和站內通知）。
- 第一版只做「系統往外推」，不做在聊天軟體裡回覆或操作系統。唯一的例外是 LINE：它要接收「Bot 被加入群組」的事件，才拿得到群組 ID（見 3.3）。
- 一個專案可以設**多個頻道**，每個頻道各自選平台、選要推的事件，也各自設定對象是內部人員還是客戶。

---

## 1. 架構

```
各 Service 觸發事件
   └─ WatchService::notifyTicket / notifyIssue（以及其他直接呼叫 events->notify 的地方）
        └─ ProjectChannelService::queue(projectId, eventCode, NotifyMessage)
             ├─ 讀出這個專案啟用中的頻道，依事件和對象過濾
             └─ 每個符合的頻道寫一筆 channel_outbox（待送）
排程（cron 每 5 分鐘）：cits:send-channels
   └─ ChannelDispatchService 取出到期的待送訊息
        └─ 依 provider 交給對應的轉接器 → 送出 HTTPS 請求 → 更新狀態，失敗就排重試
```

- **分層**：Service 不直接碰資料庫，讀寫一律透過 `ProjectChannelRepository` 和 `ChannelOutboxRepository`。
- **轉接器介面** `App\Services\Channel\Providers\ChannelProvider`：
  ```php
  interface ChannelProvider {
      public function code(): string;                       // teams|discord|telegram|line
      public function validateConfig(array $cfg): void;     // 設定不合法就丟 BusinessException
      public function render(NotifyMessage $m, array $cfg): array;  // 產生該平台要的 HTTP 內容
      public function send(array $cfg, array $payload): SendResult; // ok / 可重試 / 永久失敗 + 錯誤訊息
  }
  ```
  `send()` 用 Laravel 的 `Http` client，timeout 10 秒。`SendResult` 要能分出**可重試**（429、5xx、逾時）和**永久失敗**（400、401、403、404、410）。
- **不可寫死業務值**：平台清單、事件清單、預設勾選、允許的網域、重試間隔都放在 lookup 或 system_settings（見第 4 節）。
- 送出的部分完全在排程裡做，不在使用者操作的請求裡做。外部服務再慢也不會拖慢畫面。主機上沒有常駐的 queue worker，所以照現有 `cits:send-outbox` 的模式寫。

### NotifyMessage（不分平台的訊息內容，可序列化存進佇列）
| 欄位 | 說明 |
|---|---|
| event | 事件代碼，例如 `ticket.closed` |
| headline | 事件名稱，例如「客戶已結案」，來自 lookup `channel_event` |
| subject_no / subject_title | 單號、標題 |
| status | 目前狀態的名稱 |
| actor | 操作者姓名，排程觸發的寫「系統」 |
| occurred_at | 發生時間（UTC，顯示時轉成台北時間） |
| internal_url | `/pm/tickets/{id}` 或 `/issues/{id}`（完整網址） |
| client_url | `/tickets/{id}`，客戶看得到的事件才有 |
| client_visible | 這個事件能不能給客戶看 |
| summary | 內容摘要，可選，例如留言的前 N 個字（純文字，沒有 HTML） |
| extra | 其他欄位，例如退回次數、更新包名稱 |

---

## 2. 事件清單（lookup `channel_event`）

| 代碼 | 名稱 | 給客戶看 | 目前觸發的地方 |
|---|---|---|---|
| ticket.created | 新客戶單 | ✔ | TicketService::create |
| ticket.accepted | PM 已接收 | ✔ | TicketService::accept |
| ticket.revised | 客戶已修訂 | ✔ | TicketService::saveEdit |
| ticket.cancelled | 客戶已取消 | ✔ | TicketService::cancel |
| ticket.comment_client | 客戶留言 | ✔ | TicketService::addComment（客戶） |
| ticket.comment_pm | PM 回覆 | ✔ | TicketService::addComment（內部） |
| ticket.settled | PM 處置（狀態更新） | ✔ | TicketService::settle |
| ticket.released | 已更新至正式環境，請確認結案 | ✔ | SettlementService |
| ticket.all_excluded | 內部單都已取消，需 PM 處置 | ✘ | SettlementService |
| ticket.closed | 客戶已結案 | ✔ | TicketService::close |
| ticket.reopened | 客戶回報仍有異常 | ✔ | TicketService::reopen |
| needinfo.requested | 要求客戶補充資料 | ✔ | NeedinfoService::request |
| needinfo.answered | 客戶已補充資料 | ✔ | NeedinfoService::answeredBy |
| needinfo.overdue | 補充資料逾期 | ✔ | NeedinfoService::remind |
| issue.created | 新內部單（不在 PM 階段建立的） | ✘ | IssueService（notifyStage） |
| issue.stage_changed | 內部單換階段（派工、送測、退回、上線、取消） | ✘ | IssueService::transition |
| issue.comment | 內部留言 | ✘ | IssueService::addComment |
| issue.due_reviewed | 延期申請核准或駁回 | ✘ | IssueService::reviewDue |
| issue.test_withdrawn | 撤回送測 | ✘ | IssueService::removeFromPackage |
| package.sent_to_test | 更新包送測（**整批合併成一則**） | ✘ | IssueService::addToPackage |

- 「給客戶看」放在 lookup `channel_event_client` 裡，有列進去的就是客戶看得到的事件。
- 預設勾選放在 lookup `channel_event_default`。
- **改法**：`WatchService::notifyTicket / notifyIssue` 加一個參數 `?string $event = null`，約 20 個呼叫的地方補上事件代碼。直接呼叫 `events->notify` 的地方（延期申請審核）另外補一行 `ProjectChannelService::queue`。
- `issue.stage_changed` 的 headline 用目標狀態名稱，例如「DM-I2026-0003 → 待測試」。
- **整批送測**：`addToPackage` 在交易裡不要逐張推送，結束後統一推一則「v1.0.3 送測 5 張：DM-I…、DM-I…」，最多列 10 張，其餘寫「…等 N 張」。逐張的 `issue.stage_changed` 在這個情境要壓掉。可以在 IssueService 加一個批次模式旗標，或由 ProjectChannelService 提供 `batch()` 包住，結束時再合併。
- 寫進佇列這一步要在交易內；交易回滾時就不會有殘留的待送訊息（channel_outbox 和業務資料在同一個 DB 交易裡）。

### 給客戶看的頻道（對象設成客戶）
- 只推 `channel_event_client` 裡的事件，連結用 `client_url`。
- **絕對不能出現**：內部單號、內部留言、DEV／Test 姓名、內部狀態名稱、內部備註。
- 也要寫測試驗證這一點，可以參考 `ClientProgressService` 的處理方式。

---

## 3. 各平台規格

### 3.1 Discord（建議第一個做：免費、最容易測）
- 取得方式：在頻道設定 → 整合 → Webhook → 新增 → 複製網址，格式是 `https://discord.com/api/webhooks/{id}/{token}`。
- 送出：`POST <url>?wait=true`，JSON：
  ```json
  {"username":"CITS","allowed_mentions":{"parse":[]},
   "embeds":[{"title":"【DM-2026-0012】客戶已結案","description":"登入按鈕沒有反應",
     "url":"https://example.com/mescits/pm/tickets/12","color":3066993,
     "fields":[{"name":"狀態","value":"已結案","inline":true},{"name":"操作者","value":"客戶小王","inline":true}],
     "timestamp":"2026-11-26T06:32:00Z"}]}
  ```
- 一定要帶 `allowed_mentions.parse=[]`，避免內容裡的 `@everyone` 真的去 tag 所有人。
- 長度限制 ⚠ 待確認：title 256 字、description 4096 字、每則最多 10 個 embed，超過要截斷。
- 回 429 時，回應內容會有 `retry_after`（秒），照它排下一次重試。404 代表 webhook 被刪了，算永久失敗。
- 允許的網域：`discord.com`、`discordapp.com`。

### 3.2 Telegram
- 取得方式：
  1. 找 @BotFather 建立 Bot，拿到 Bot Token。
  2. 把 Bot 拉進群組，要讓它可以發言。
  3. 設定頁按「偵測群組」：系統呼叫 `GET https://api.telegram.org/bot<token>/getUpdates`，列出最近有互動的 chat，讓管理員選要用哪一個。
  4. 請管理員先在群組裡隨便講一句話或 @Bot，getUpdates 才看得到這個群組。
- 送出：`POST https://api.telegram.org/bot<token>/sendMessage`
  ```json
  {"chat_id":-1001234567890,"parse_mode":"HTML","disable_web_page_preview":true,
   "text":"<b>【DM-2026-0012】客戶已結案</b>\n登入按鈕沒有反應\n狀態：已結案｜操作者：客戶小王",
   "reply_markup":{"inline_keyboard":[[{"text":"開啟","url":"https://.../pm/tickets/12"}]]}}
  ```
- 用 HTML 格式時，所有文字都要先做 escape，只能用 Telegram 允許的標籤，例如 `<b>`、`<a>`。
- 長度限制 ⚠ 待確認：文字最多 4096 字；同一個群組約每分鐘 20 則，超過會回 429 並帶 `parameters.retry_after`。
- 群組升級成超級群組時會回 400，並帶 `migrate_to_chat_id`。要自動更新 chat_id 並重送，同時記錄下來。
- 401 代表 Token 錯了，403 代表 Bot 被踢出群組，都算永久失敗。
- Token 等同密碼，要加密儲存；**log 和錯誤訊息裡要把網址中的 token 遮掉**。
- 允許的網域：`api.telegram.org`。

### 3.3 LINE 群組（Messaging API）
- LINE Notify 已經停止服務，只能用 **LINE 官方帳號加 Messaging API**。
- 取得方式：
  1. 在 LINE Developers 建立 Provider，再建立 Messaging API channel（會綁一個官方帳號）。
  2. 拿到 **Channel access token**（長期有效的）和 **Channel secret**。
  3. 在 LINE Official Account Manager 的設定裡，打開「允許加入群組」。
  4. 把 Webhook URL 設成 `https://example.com/mescits/hooks/line/{channelKey}`（channelKey 是每個頻道的隨機字串）。
  5. 把官方帳號邀進群組。LINE 會送出 `join` 事件，裡面有 `source.groupId`，系統收到後自動存進這個頻道的 `chat_id`。
- **接收端點**（新路由，不套 CSRF、不需要登入）：`POST /hooks/line/{channelKey}`
  - 用 channel secret 對**原始請求內容**算 HMAC-SHA256，Base64 後和 header `x-line-signature` 比對，要用固定時間的比對函式；不符就回 403。
  - 只處理 `join` 和 `leave` 事件：join 記下 groupId，leave 把這個頻道停用並通知管理員。其他事件一律回 200 後忽略。
  - 要限制請求內容大小，例如 64 KB，並把收到的事件寫進操作紀錄。
- 送出：`POST https://api.line.me/v2/bot/message/push`，header `Authorization: Bearer <token>`，可以加 `X-Line-Retry-Key: <uuid>` 防止重送時重複發出。
  ```json
  {"to":"C1234567890abcdef...","messages":[{"type":"flex","altText":"【DM-2026-0012】客戶已結案",
    "contents":{"type":"bubble","body":{"type":"box","layout":"vertical","contents":[
      {"type":"text","text":"【DM-2026-0012】客戶已結案","weight":"bold","wrap":true},
      {"type":"text","text":"登入按鈕沒有反應","wrap":true},
      {"type":"text","text":"狀態：已結案｜操作者：客戶小王","size":"sm","color":"#888888","wrap":true}]},
     "footer":{"type":"box","layout":"vertical","contents":[{"type":"button","action":{"type":"uri","label":"開啟","uri":"https://.../tickets/12"}}]}}}]}
  ```
- ⚠ 待確認：**費用和則數上限**。免費方案每月有則數上限，推送到群組時可能是**依群組人數計算則數**。實作前請到官方確認台灣的方案。
  - 設定頁要顯示本月已推送的則數，並提供系統設定 `channel.line_monthly_cap`；超過上限就不再推送並通知管理員。
  - LINE 有提供查詢本月用量的 API ⚠ 待確認端點，可以用來顯示。
- 長度限制 ⚠ 待確認：altText 400 字、純文字 5000 字。
- 允許的網域：`api.line.me`。

### 3.4 Microsoft Teams（只有公司或學校帳號才能用；家用版不支援 Workflows）
- 取得方式：在頻道的「…」→ Workflows → 選「收到 Webhook 要求時張貼到頻道」範本，完成後複製網址。
- 送出：`POST <url>`，JSON：
  ```json
  {"type":"message","attachments":[{"contentType":"application/vnd.microsoft.card.adaptive",
    "content":{"$schema":"http://adaptivecards.io/schemas/adaptive-card.json","type":"AdaptiveCard","version":"1.4",
      "body":[{"type":"TextBlock","text":"【DM-2026-0012】客戶已結案","weight":"Bolder","wrap":true},
              {"type":"TextBlock","text":"登入按鈕沒有反應","wrap":true},
              {"type":"FactSet","facts":[{"title":"狀態","value":"已結案"},{"title":"操作者","value":"客戶小王"}]}],
      "actions":[{"type":"Action.OpenUrl","title":"開啟","url":"https://.../pm/tickets/12"}]}}]}
  ```
- 成功時通常回 202 ⚠ 待確認。
- 允許的網域 ⚠ 待確認：`*.logic.azure.com`、`*.environment.api.powerplatform.com`、`*.webhook.office.com`。舊版 Office 365 Connector 正在被淘汰，不要做。

---

## 4. 資料與設定

### 4.1 Migration：`2026_12_xx_000100_create_project_channels.php`
```
project_channels
  id, project_id FK
  provider varchar(20)          -- teams|discord|telegram|line（lookup channel_provider）
  name varchar(100)             -- 顯示名稱，例如「開發群」
  enabled bool default 1
  audience varchar(20)          -- internal|client（lookup channel_audience）
  include_summary bool default 0
  events json                   -- 事件代碼陣列
  credential text null          -- 加密：webhook 網址（teams/discord）或 bot token（telegram）或 access token（line）
  secret text null              -- 加密：LINE channel secret
  chat_id varchar(100) null     -- telegram chat_id / line groupId
  channel_key varchar(40) unique -- LINE 接收網址用的隨機字串
  failure_count int default 0, last_ok_at datetime null, last_error varchar(500) null
  created_by FK users, timestamps
  index (project_id, enabled)

channel_outbox
  id, project_channel_id FK（cascade delete）
  event varchar(50), payload json（NotifyMessage）
  status varchar(20)            -- pending|sent|failed|skipped
  attempts int default 0, next_attempt_at datetime, sent_at datetime null
  error varchar(500) null, created_at
  index (status, next_attempt_at)
```
- 加密用 Laravel 的 `Crypt`，放在 Repository 或 Model 的 cast 做（例如 `encrypted` cast），Service 只會拿到明文。畫面上永遠不回傳明文，只顯示「已設定」和網址或 token 的最後 4 碼。
- 正式站 sql_mode 是空的，**varchar 太長會被直接截斷**，所以存入前要在程式裡先截斷長度。新的種子值要通過 `SeedDataLengthTest`。
- `events` 存的是代碼陣列；如果之後要讓管理員調整清單，再改成 lookup。

### 4.2 Lookup 與系統設定（寫進 `database/seeders/data/workflow.php`，升級 SQL 會自動用 INSERT IGNORE 帶上）
| 類別 | 內容 |
|---|---|
| channel_provider | teams=Microsoft Teams, discord=Discord, telegram=Telegram, line=LINE 群組 |
| channel_audience | internal=內部人員, client=含客戶 |
| channel_event | 第 2 節的事件代碼和名稱 |
| channel_event_client | 客戶看得到的事件代碼 |
| channel_event_default | 新增頻道時預設勾選的事件 |
| channel_host_discord / _telegram / _line / _teams | 各平台允許的網域，`*.` 開頭代表允許子網域 |
| event_label | channel_test=頻道測試, channel_disabled=頻道自動停用 |

| 系統設定 | 預設 |
|---|---|
| channel.retry_minutes | `5,15,60,180,720` |
| channel.max_attempts | 5 |
| channel.disable_after_failures | 10（連續永久失敗幾次後自動停用頻道） |
| channel.summary_chars | 100 |
| channel.batch_limit | 50（每次排程最多送幾則） |
| channel.line_monthly_cap | 0（0 = 不限制） |

### 4.3 權限
- 新增權限 `project.channels`，給 `project_admin` 和 `internal_pm`；系統管理員本來就全部可以用。
- 客戶類型的帳號不能設定頻道。

---

## 5. 畫面

**專案管理 → 通知頻道**（`/project-admin/channels`）
- 列表欄位：名稱、平台、對象、啟用狀態、最近成功時間、最近錯誤、本月推送則數，以及「測試」「編輯」「停用」按鈕。
- 新增或編輯頁：
  - 選平台後，依平台顯示對應欄位和**設定步驟說明**（第 3 節的取得方式，寫成短步驟）。
  - Telegram 多一個「偵測群組」按鈕；LINE 顯示接收網址讓使用者複製，並顯示「等待加入群組… / 已綁定群組」。
  - 勾選要推的事件：對象是客戶時，只能勾客戶看得到的事件。
  - 「包含內容摘要」開關。開啟時要提醒：內容會留在外部平台，系統的權限控管就管不到了。
- 「傳送測試訊息」：**直接同步送出**，不進佇列，結果立刻顯示在畫面上。
- 最近 50 筆推送紀錄：時間、事件、狀態、錯誤原因，錯誤訊息裡的 token 要遮掉。
- 手冊：第 8 章（專案管理）新增一節，四個平台各附幾張設定截圖。

---

## 6. 寄送、重試與安全

- `cits:send-channels`：在 `routes/console.php` 設成每分鐘執行並加 `withoutOverlapping()`（cron 實際每 5 分鐘觸發一次）。
  - 每次取 `pending` 而且 `next_attempt_at <= now` 的訊息，數量上限是 `channel.batch_limit`。
  - 取出時要把該筆鎖住或先改狀態，避免同時有兩個排程重複送出。
- 重試：可重試的失敗依 `channel.retry_minutes` 的間隔排下一次；平台回應裡有 retry_after 時照它的時間。超過 `max_attempts` 就標成 failed。
- 永久失敗：記錄錯誤、累加失敗次數；達到 `disable_after_failures` 就自動停用頻道，記 `channel_disabled` 事件，並用站內通知加 Email 告訴專案管理員。
- 對象是客戶的頻道，只推客戶看得到的事件，連結一律用 `client_url`。這個過濾在寫進佇列時做一次，送出前再檢查一次。
- **SSRF 防護**：
  - 網址必須是 https，主機名稱要符合該平台的允許網域，不能是 IP、不能帶帳密、不能指定 port。
  - 送出前再檢查一次，因為資料庫裡的值可能被改過。
  - 不跟隨轉址（`withoutRedirecting()`）。
- 存進佇列的內容只有 NotifyMessage，不存 token。token 在送出時才從頻道設定解密取得。
- 本機和測試環境一律用 `Http::fake()`，**測試絕對不能打到外部服務**。

---

## 7. 測試（Pest）

- 每個轉接器：`render()` 的輸出符合平台格式（截斷、escape、`allowed_mentions`、HTML escape、Flex 結構）；`send()` 能正確分出可重試、永久失敗（429 和 retry_after、401、404、Telegram 的 migrate_to_chat_id）。
- 事件寫進佇列：一個事件只產生一則訊息、會依事件過濾、對象是客戶的頻道不會收到內部事件、內容不含內部單號；交易回滾時不留下待送訊息。
- 整批送測會合併成一則，逐張的階段變更被壓掉。
- 排程：重試間隔、超過上限標成 failed、連續失敗後自動停用，以及兩個排程同時跑不會重複送出。
- LINE 接收端點：簽章錯誤回 403；join 事件會存下 groupId；leave 事件會停用頻道；過大的請求被拒絕。
- 權限：客戶和沒有權限的內部人員都會被擋（403）；畫面上看不到明文的 token。
- SSRF：不合法的網域、IP、http 網址都存不進去。
- 目前全套 207 個測試全過，**開發完也必須全過**。

---

## 8. 分期建議

| 階段 | 內容 |
|---|---|
| 1 | 資料表、Repository、ProjectChannelService、佇列、排程、**Discord 轉接器**、設定頁、測試訊息、事件串接（含整批送測合併） |
| 2 | Telegram（含偵測群組）、Teams 轉接器 |
| 3 | LINE（接收端點、綁定群組、用量上限） |
| 4（可選） | 每日摘要：例如台北 09:30（01:30 UTC，**分鐘數必須是 5 的倍數**）推「今天待處理：客戶單 3、待測試 5、逾期 2」 |

---

## 9. 開工前要請使用者決定的事

1. 第一版先做哪個平台？建議 Discord 或 Telegram。
2. 有沒有含客戶的群組？沒有的話，第一版可以只做「內部人員」對象。
3. 「包含內容摘要」預設要開還是關？建議關。
4. LINE 的費用和則數上限，請使用者到官方確認。

---

## 10. 實作和部署時要遵守的規則（摘要，完整內容見 PROJECT_CONTEXT）

- Service 層不可直接操作資料庫；業務值不可寫死；只能修改使用者指定的檔案；不能自行 commit 或 push。
- 有改到檔案就要在 `.histories/<名稱>-<時間>.md` 留紀錄；完成後更新 `SESSION_LOG.md`（只能用 ASCII 字元）。
- 資料表的變更要寫 migration（資料 migration 要用 `DB::statement` 寫原生 SQL）。用 `deploy/make_upgrade_sql.php` 產生升級 SQL，交給使用者在 phpMyAdmin 執行，執行前要先選好 `cpuser_mescits`。AI 不直接改正式站資料庫。
- 部署照 `docs/PLATFORM_DEV_RECORD.md` 第 9 節的凍結快照流程。上線後要用三種身分跑登入測試，也要另外測 LINE 接收端點：沒有簽章時要回 403。
- 正式站沒有 SSH，排程是 cPanel cron 每 5 分鐘一次，所以推送最多會晚 5 分鐘。
