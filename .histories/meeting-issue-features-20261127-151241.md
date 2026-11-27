# 會議：跨會議歷程、事項附圖、事項轉內部單

時間：2026-11-27 15:12（Asia/Taipei）。未提交、未部署。

## 1. 跨會議歷程

- `MeetingRepository`：新增 `itemsForIssues()`、`itemWithIssue()`、`lockItem()`、`itemIds()`；`item()` 的 owner 多讀 `user_type`、`is_admin`。
- `MeetingService`：
  - `issueThreads()`：依 `carried_from_id` 分組成討論串。
  - `itemHistory()`：同一張內部單在其他會議的事項，只給內部人員。
  - `issueClosed()`、`issueStatusLabel()`。
  - `itemFields`：同一場會議同一張內部單只能有一個事項。
  - `scheduleNext(..., $skipClosed)`：可略過內部單已結案的事項。
  - `markPublished` → `announceConclusions()`：第一次發布時寫入 issue 事件 `meeting_discussed`，並通知關注者。
- `IssueService::eventNote`：加入 `meeting_discussed`、`from_meeting` 的說明文字。
- `PmQueryService`：`meetingItems` 改為 `meetingThreads`。
- 畫面：
  - `issues/show.blade.php`：會議紀錄改成依討論串分組。
  - `meetings/_item.blade.php`：加「內部單已結案」標籤，引用新的 `_item-history.blade.php`。
  - `show.blade.php`：排下次會議加 `skip_closed` 勾選。
- `workflow.php`：`event_label` 新增 `meeting_discussed`、`from_meeting`。正式站要跑升級 SQL（`make_upgrade_sql.php`）。

## 2. 事項附圖

- 不改資料表：沿用 `attachments`，`owner_type = meeting_item`。
- `config/attachments.php`：`meeting_item => meeting.view`。
- `config/meeting.php`：`item_images_max`，預設 10。
- `AttachmentRepository`：
  - `projectIdForOwner` 支援 `meeting_item`。
  - 新增 `meetingIdForItem`、`owned`、`countOwned`、`tempImage`、`bind`、`takeFile`、`duplicate`。
- `AttachmentService`：
  - `canView`：`meeting_item` 不走管理員捷徑；客戶要 `meetingOpenToClients` 才看得到。
  - 新增 `ownedBy`、`countOwned`、`bindImage`、`replaceImage`（同 id 換檔，舊檔沒人引用就刪）、`deleteFiles`、`copyBound`、`copyAsTemp`。
- `MeetingService`：
  - 新增 `itemImages`、`historyImages`、`addItemImage`、`replaceItemImage`、`removeItemImage`。
  - 刪除事項、永久刪除會議時一併刪圖；排下次會議時複製圖。
- 路由：`POST|PUT|DELETE /meetings/{id}/items/{item}/images[/{image}]`。
- 畫面：
  - 新增 `_item-images.blade.php`、`_item-image.blade.php`。
  - `items.js`：貼上、拖曳、選檔上傳、燈箱、標記。標記沿用 `editor/drawing.js` 的 `openDrawing`；PNG 內有 `tEXtmxfile` 就重新編輯原繪圖。

## 3. 事項轉內部單

- `MeetingService`：新增 `issueOrigins`、`canConvert`、`issueDraftContext`、`issueDraft`、`convertItem`。
  - `issueDraft`：預填內容，事項圖片以暫存附件複製一份。
  - `convertItem`：在同一個交易裡鎖住事項、呼叫 `IssueService::createInternal`、寫入 `issue_id`、記 `from_meeting` 事件、讓內部負責人自動關注。
- `MeetingController`：新增 `issueForm`、`issueStore`、圖片 API、`imageJson`；`show` 多傳 `history`、`images`、`canConvert` 等變數。
- 路由：`GET|POST /meetings/{id}/items/{item}/issue`。
- 畫面：
  - 新增 `meetings/issue-create.blade.php`。
  - 事項列加「轉內部單」按鈕。

## 文件與測試

- 文件：`docs/manual/11-meetings.md`、`docs/meeting-system.md`。
- 測試：新增 `tests/Feature/MeetingIssueFeaturesTest.php`（6 個）。全部 263 個通過。
- 沒做瀏覽器實測：本機帳密不能讀，所以貼圖和 draw.io 標記的實際操作還沒驗證。
