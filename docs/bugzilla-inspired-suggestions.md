# Bugzilla 啟發功能建議（待主線判斷）

狀態：提案，尚未實作。日期：2026-11-26。
目的：整理 6 項參考 Bugzilla 後可補強的功能，附上對應的現有模組與風險，供主線決定做不做、先後順序。

## 共通限制（所有項目適用）

- 客戶只能看到 `client_tickets` 與自己專案的資料；`issues`（內部）內容不得透過任何客戶端端點回傳（含搜尋結果、標題、通知）。
- 內部備註（`issue_comments` 內部頻道）不得流向客戶。
- 寫入路徑：Controller 驗證 → Service（BLL，不碰 DB）→ Repository（DAL）。
- 新增 / 修改 DB 結構：寫 migration + `deploy/make_upgrade_sql.php` 產出升級 SQL，由使用者在 prod 執行；AI 不改 prod DB。
- 新 lookup 值放 `workflow.php` 並進升級 SQL，不得在程式碼寫死。
- 通知一律走 `OutboxService`，不直接寄信。

## 優先序總覽

| 順序 | 項目 | 需改 schema | 主要模組 | 建議判斷 |
|---|---|---|---|---|
| P1 | 3. needinfo 旗標 | 是（小） | IssueService, Notification, Client ticket view | 最快見效，解決「PM 要客戶補資料卻要改狀態」的痛點 |
| P1 | 2. CC / Watch 清單 | 是（小） | NotificationService, OutboxService | 補 assignee 之外的通知缺口 |
| P2 | 1. 建立時重複偵測 | 否 | TicketService, TicketRepository, IssueRepository | 減少重複 ticket / issue，搜尋邏輯需先定可見範圍 |
| P2 | 5. 儲存查詢 | 是（小） | PmQueryService, filtered() | PM 日常效率，改動集中 |
| P3 | 4. 依賴關係（blocks） | 是（中） | IssueService, SettlementService | 影響派工與結算，風險最高，需先定規則 |
| P3 | 6. Whiteboard / 標籤 | 是（小） | IssueService, lookup | 跨專案分類，價值取決於是否需要客戶可見 |

---

## 1. 建立時重複偵測

**問題**
客戶與 PM 建立 ticket / issue 時沒有相似項提示，重複內容會累積，之後靠人工合併（`ticket_issue_links`）。

**建議做法**
- 新增 AJAX 端點 `GET /tickets/similar?title=...`（客戶端）與 `GET /pm/issues/similar?title=...`（內部端點）。
- 評分：標題 + 內文前段的關鍵字重疊（中文以 2-gram 切分），以 LIKE 查候選，再在 PHP 端排序。現況無 FULLTEXT / ngram 外掛，因此不做資料庫端全文索引。
- 候選上限 5 筆，只回傳 id、標題、狀態、建立日期。
- 排除已 cancelled 與 `is_excluded=1` 的項目。
- 建立頁顯示提示，使用者可選「仍要建立」或「改為連結到既有項目」（既有的 link 流程可直接複用）。

**客戶端限制（重要）**
- 客戶端搜尋只查 `client_tickets`，**不得查 `issues`**，否則會洩漏內部 issue 標題。
- 內部端搜尋可同時查兩者，結果需標示類型。

**涉及**
- `TicketService`、`IssueService`（新方法 `findSimilar`，只組裝條件，不碰 DB）
- `TicketRepository`、`IssueRepository`（新查詢方法）
- 新 Controller 方法 + route（`routes/modules/` 下對應檔案）
- 前端：建立表單的 Alpine 元件

**不需要 schema 變更。**

**待主線決定**
- 相似度門檻與是否顯示分數。
- 客戶端是否允許看到「其他人的相似 ticket」（參考 Business Rule：同專案客戶可互相看到 ticket，所以應可行，但需確認）。

---

## 2. CC / Watch 清單

**問題**
通知目前以 assignee、ticket 提交人為主，其他關心者（PM、客戶窗口、主管）無法訂閱單一 issue 的變更。

**建議做法**
- 新表 `issue_watchers`（`issue_id`, `user_id`, `created_at`，`unique(issue_id, user_id)`）與 `ticket_watchers`（同結構）。
- 自動訂閱規則：建立者、assignee 變更時自動加入；可手動加減。
- 變更事件（狀態、留言、dispatch、released）一律透過 `NotificationService` 展開收件人，再進 `OutboxService`。
- 收件人展開時套用可見性：
  - 客戶 watcher 只接收 `ticket_*` 與客戶頻道留言的通知。
  - 內部 watcher 不受限。
  - 客戶 watcher 不得收到內部留言、內部 issue 的狀態細節。
- 使用者可在 `/account` 設定「自動訂閱我建立的項目」（沿用 `users.email_notify` 的設定模式）。

**涉及**
- 新 migration + 升級 SQL
- `NotificationService`（收件人展開）
- `OutboxService`（不改，只新增 template key）
- 新 lookup：`watch_event`（若要事件可選擇），否則在程式碼以 config 定義（需主線決定，避免寫死）

**風險**
- 收件人數量大時，單次事件會寫入大量 outbox 列。建議上限（例如 50）並記錄超出數。
- SMTP 目前未在 prod 驗證，通知先以站內通知為準。

**待主線決定**
- 客戶是否可以手動 watch 內部 issue（建議：不可，只能 watch 自己的 ticket）。

---

## 3. needinfo 旗標

**問題**
PM 要向客戶要資料（截圖、log、重現步驟）時，目前只能改狀態或留言。若改狀態，會影響結算與 chain lock 的判斷；若只留言，客戶看不出「需要回覆」。

**建議做法**
- 不新增狀態，改用旗標：在 `client_tickets` 加 `needinfo_at`、`needinfo_note`（或獨立表 `ticket_needinfo`，若要保留歷史）。
- PM 設定 needinfo 時必填說明（寫入 `ticket_comments` 客戶頻道，客戶可見）。
- 客戶回覆（任何 ticket 留言或附件上傳）後自動清除旗標，並寫入 `events`。
- 清單頁新增篩選「等待客戶回覆」與「客戶已回覆、待 PM 處理」。
- 逾期提醒：超過設定天數（`project_settings`，不寫死）未回覆，寄通知給 PM。

**與既有規則的關係**
- 旗標不影響 ticket 狀態，**不影響結算、不影響 chain lock**，這是選擇旗標而非狀態的主因。
- 客戶仍只能留言（既有規則），不新增客戶可執行的動作。

**涉及**
- migration（`client_tickets` 欄位或新表）+ 升級 SQL
- `TicketService`（設定 / 清除旗標）、`TicketRepository`
- `events` 新 event_label（lookup）
- 客戶與 PM 的 ticket 頁面、清單篩選

**待主線決定**
- 欄位放在 `client_tickets` 還是獨立表（獨立表保留多次 needinfo 歷史，建議）。
- 內部 issue 是否也要 needinfo（建議先只做 ticket）。

---

## 4. 依賴關係（blocks / depends on）

**問題**
目前 `ticket_issue_links` 只能表示「合併」，無法表示「A 完成前 B 不能進 DEV」。

**建議做法**
- 新表 `issue_dependencies`（`from_issue_id`, `to_issue_id`, `kind`，`kind` 用 lookup `dependency_kind`：blocks / related）。
- 只對 `blocks` 做規則檢查；`related` 純展示。
- 寫入時做循環檢查（DFS，節點數上限 200）。
- 派工規則：被 blocks 的 issue，若上游未 released / 未 excluded，不得 dispatch 到 DEV。

**與既有規則衝突點（需主線決定）**
- 目前 Business Rule：Test 新發現 issue 直接進 DEV。若有 blocks 關係，該規則需例外或被阻擋。建議：新發現 issue 不可被 blocks，本身不會有上游。
- Dispatch 目前鎖 ticket（依 id 排序）再鎖 issue。新增依賴檢查若需讀取其他 issue 狀態，必須在同一鎖序內完成，否則有死鎖風險。建議檢查只讀、不加鎖，並在 dispatch 交易內重新驗證一次。
- 結算（SettlementService）是否要考慮上游未完成：建議**不**納入，避免 ticket 因上游 issue 卡住而無法結算。

**涉及**
- migration + 升級 SQL
- `IssueService`（dispatch 前檢查）、`SettlementService`（不改，明確排除）
- Issue 詳情頁、列表標示（阻塞中）

**風險**
- 最高。影響派工，且與 chain lock、排序鎖交互。建議在 P3 前先寫 Spec（`docs/`）並補測試，再實作。

---

## 5. 儲存查詢

**問題**
PM / DEV / Test 每天重複設定同樣的篩選條件（例如「本週逾期」「DEV 待接」）。

**建議做法**
- 新表 `saved_queries`（`user_id`, `project_id`, `scope`（client / pm / dev / test）, `name`, `params` JSON, `sort_order`）。
- 儲存的是 `filtered()` 的輸入參數，**不存 SQL**。讀取時重新經過既有 filter builder，確保權限與欄位白名單仍生效。
- `params` 寫入前做白名單驗證（只允許既有篩選 key）。
- 列表頁上方加「我的查詢」下拉，支援建立 / 改名 / 排序 / 刪除。
- 查詢只屬於建立者，不共享（之後若要共享再加 `is_shared`）。

**風險**
- 若存完整 SQL 或任意欄位名，會有注入與權限繞過風險。必須只存白名單 key。
- 查詢中含角色權限的條件（例如 `mine`）時，套用時仍以當下使用者計算，不得存他人的結果。

**涉及**
- migration + 升級 SQL
- `PmQueryService` / 各 list service 的 filter 入口（只讀取，不改邏輯）
- 前端下拉元件

**不需要改現有查詢邏輯。**

---

## 6. Whiteboard / 標籤

**問題**
沒有跨專案的自由分類方式。目前只能靠 origin、kind、process_type 這類固定 lookup，無法表達「同一批 MES 頁面的問題」「客戶 A 的 UI 問題」這類臨時分組。

**建議做法**
- 兩種選擇，需主線擇一：
  - **A. 受控標籤**：`tags` 表（由 admin 管理，lookup 型）+ `issue_tags` / `ticket_tags`。可控、可報表。
  - **B. 自由標籤**：使用者直接輸入，寫入前正規化（trim、小寫、長度上限 30）。彈性高，但會髒。
- 建議 A，因為專案規則要求不寫死值、可透過後台維護，受控標籤符合既有 lookup 模式。
- Whiteboard 這種純文字欄位不建議加，與標籤功能重疊。

**與客戶可見性**
- 標籤預設只給內部使用。若要給客戶看，需 `tags.customer_visible` 欄位，且 `client_tickets` 只能掛 customer_visible 標籤。

**涉及**
- migration + 升級 SQL
- `workflow.php` / lookup（若採 A，標籤主檔走 `lookup_editable` 機制，參考 2026-11-26 lookup admin 做法）
- 列表篩選（多選 AND / OR 需決定）
- Excel 匯出欄位（`export_<list>` lookup）

**待主線決定**
- AND 或 OR 篩選。
- 是否需要客戶可見標籤。

---

## 建議實作順序（若主線同意）

1. **needinfo（3）**：獨立、低風險、不動結算，先做。
2. **CC / Watch（2）**：與 needinfo 共用通知展開邏輯，可一起設計。
3. **重複偵測（1）**：無 schema 變更，可與上面並行。
4. **儲存查詢（5）**：獨立。
5. **標籤（6）**：等 1–5 穩定後再做。
6. **依賴（4）**：最後做，且先寫 Spec 與 SettlementService / dispatch 的交互測試。

## 需要主線判斷的總清單

1. needinfo 欄位放在 `client_tickets` 或獨立表。
2. 客戶可否 watch 內部 issue（建議否）。
3. 重複偵測中，客戶端是否可見其他人的相似 ticket。
4. 依賴關係下，Test 新發現 issue 的既有規則是否例外。
5. 標籤採受控（建議）或自由輸入。
6. 標籤篩選 AND 或 OR。

## 不建議採納的 Bugzilla 功能（僅記錄）

- **Bugzilla 的 Bug visibility groups**：CITS 已用 `client_tickets` / `issues` 實體分離達成，不需再加一層。
- **Time tracking（估時 / 實際工時）**：與目前「時間軸取代 SLA」的設計方向相反，未列入。若主線要做，建議獨立提案。
- **XML-RPC / JSON-RPC**：已有 MCP 唯讀端點，不重複建置。

## 參考

- Bugzilla 官方文件：https://bugzilla.readthedocs.io/en/latest/ （功能描述來自既有知識，未即時比對，版本細節待確認）
- 專案內相關文件：`PROJECT_CONTEXT.md`（Business Rules）、`docs/flow-v2-and-project-admin.md`、`docs/meeting-system.md`（格式參考）
