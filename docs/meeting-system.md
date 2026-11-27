# 會議紀錄系統規劃（2026-11-26 定案）

目的：內部會議針對問題點討論並記下結論；客戶會議快速紀錄並給客戶看；可排定未來會議。不做視訊。

## 1. 可見範圍

| 會議 | 誰能發起 | 內部人員 | 該專案客戶 |
|---|---|---|---|
| 內部發起，未開放 | 任何有 meeting.create 的內部成員（可排定，也可「立即開始」） | 看得到 | 看不到 |
| 內部發起，開放給客戶 | 同上 + meeting.share 勾「開放給客戶」 | 看得到 | 看得到 |
| 客戶發起 | 該專案有 meeting.create 的客戶 | 看得到 | 全部看得到（固定開放，不能改內部） |

- 內部備註（internal_html）永遠只有內部看得到。
- E1：內部發起且開放的會議，客戶先看到時間、地點、與會者、預定問題點；討論內容、結論、補充紀錄要等「完成紀錄」（發布）後才看得到。
- E2：開放對象＝整個專案的客戶。
- E3：可以取消開放，留事件紀錄；客戶因此看不到該會議（含他們的留言）。
- E5：客戶發起的會議內容一律即時可見，不用發布。

## 2. 編輯與操作權限

| 動作 | 內部發起 | 客戶發起 |
|---|---|---|
| 編輯（基本資料、討論事項、補充紀錄） | 發起人、內部與會者、meeting.manage | 發起人、與會者（不分內外）、meeting.manage |
| 取消 / 恢復 | 發起人、meeting.manage | 發起人（客戶）、meeting.manage |
| 開放給客戶 | meeting.share（內部人員） | 不適用 |
| 刪除（進專案回收筒） | meeting.delete（project_admin） | 同左 |
| 留言 / 回覆 | 看得到就能留（meeting.comment）；內部可勾「僅內部」 | 同左 |

- 編輯者另需 meeting.create（client_view 永遠唯讀）。
- E7：發起時與會者、負責人可選該專案所有成員。

## 3. 狀態（workflow scope meeting）

已排定 scheduled → 紀錄中 recording → 已完成 done（is_released＝發布，第一次到達寫 published_at）；
scheduled / recording → 取消 cancelled（terminal，不能編輯）；cancelled → scheduled（恢復）。
已完成仍可修改，客戶看到「最後更新」，事件紀錄記下修改。

討論事項狀態 scope meeting_item：追蹤中 open → 已完成 done（terminal）。未完成（非 terminal）的事項在「安排下次會議」時帶過去。

### 跨會議、附圖、轉內部單（2026-11-27）

- 一張內部單可出現在多場會議（meeting_items.issue_id 多對一）；同一場會議同一張單只能一個事項（itemFields 檢查）。
- 內部單頁「會議紀錄」依 carried_from_id 分組成討論串（MeetingService::issueThreads）；會議頁每個事項的「歷次討論」= 同單在其他會議的事項（itemHistory，僅內部）。
- 第一次發布（markPublished）時，連單且有結論的事項寫 issue 事件 meeting_discussed 並 notifyIssue；之後修改不再通知。
- 安排下次會議可勾 skip_closed：連結內部單已 terminal 的事項不帶過去。
- 事項附圖：attachments.owner_type = meeting_item（不改表）。上傳走 POST /uploads/image（context meeting:item:{meetingId}），再 POST /meetings/{id}/items/{item}/images 綁定；PUT 換成標記後的版本（同 id、同位置，舊檔無人引用即刪）；DELETE 移除。上限 config meeting.item_images_max（預設 10）。客戶權限同討論內容（AttachmentService::canView → meetingOpenToClients）。排下次會議複製附件列（同 disk_path）。
- 轉內部單：GET/POST /meetings/{id}/items/{item}/issue。來源選項 = lookup issue_create_perm 中使用者有權限者；IssueService::createInternal 建單，同交易鎖事項、寫 issue_id、事件 from_meeting、負責人（內部）自動關注。事項附圖以暫存附件複製到草稿 context issue:meeting-item:{itemId}，建單時隨內容綁到 issue。

## 4. 資料表

- meetings：project_id, type, title, start_at, end_at, location, status, customer_visible, notes_html, internal_html, notes_rev, published_at, prev_meeting_id, created_by, updated_by, deleted_at, deleted_by
- meeting_attendees：meeting_id, user_id | external_name
- meeting_items：meeting_id, sort, topic, issue_id, discussion, conclusion, owner_id, due_date, status, carried_from_id
- meeting_comments：meeting_id, parent_id（一層）, user_id, body, is_internal, edited_at, deleted_at, deleted_by
- meeting_reads：meeting_id, user_id, read_at（未讀留言數）

設定資料（workflow.php → make_upgrade_sql.php）：statuses meeting / meeting_item、transitions、lookups meeting_type（加入 lookup_editable）、meeting_initial、meeting_item_initial、event_label、權限 meeting.view / create / share / comment / manage / delete。

## 5. 畫面

- 頂列「流程圖」後面「會議」。
- 列表：未完成（時間正序）／已完成／取消／我的待辦，搜尋標題與內容。未讀留言數。
- 會議頁：基本資料、與會者、討論事項表（邊打邊存、Enter 新增一列、↑↓ 排序）、補充紀錄（rich text）、內部備註、留言區、安排下次會議、加入行事曆（.ics）。
- 問題單頁：「會議紀錄」區塊（依討論串分組：哪些會議討論過、每場討論與結論）＋「排入會議」。
- 專案管理 → 回收筒：會議還原 / 永久刪除（需輸入標題）。

## 6. 第一版不做

Email 通知（等 SMTP / cron）、留言附圖（留言為純文字）、會議快照比對、週期規則自動產生。
