# 單號產生：跳過其它專案已用過的號碼

時間：2026-11-28 14:05（Asia/Taipei）。未提交、未部署。

## 問題

使用者把 `20260514_MES問題清單_標準格式.xlsx` 匯入專案 TN_MES_01（前綴 YC）。預覽正常：新建 49、略過 10。按確認匯入、上傳 44 張圖後，畫面顯示 Server Error。

正式站 log（2026-11-28 05:29、05:33 UTC）：`Duplicate entry 'YC-I2026-0002' for key 'issues_issue_no_unique'`。

## 原因

- `project_counters` 是每個專案各自計數，但 `issues.issue_no`、`client_tickets.ticket_no` 是全系統唯一。
- 專案 #1（CITSTest）以前用過前綴 YC，到現在還擁有：
  - 內部單 `YC-I2026-0002`～`0004`
  - 客戶單 `YC-2026-0001`～`0003`
- 後來專案 #1 改用前綴 Test，YC 給了專案 #2。
- 專案 #2 的計數器從 1 開始：第 1 列取得 0001，第 2 列取得 0002，和專案 #1 撞號。整批匯入在同一個交易裡，所以全部 rollback，畫面顯示 500。
- 專案 #2 開客戶單也會撞號，前 3 張就會撞。
- 專案前綴本來就驗證「現有專案之間不能重複」，但擋不到「以前用過、現在已經換掉」的前綴。

## 修改

- `cits/app/Repositories/CounterRepository.php`：新增 `numberTaken(kind, number)`。
  - `NUMBER_COLUMNS` 對應：ticket 查 `client_tickets.ticket_no`，issue 查 `issues.issue_no`。
  - 用 `DB::table` 查，回收筒裡的單也算已使用。
- `cits/app/Services/NumberingService.php`：產生的號碼已被使用就跳過，計數器跟著往前，再取下一號。
  - 最多跳 10000 次，超過就丟例外，避免無限迴圈。
- 新增 `cits/tests/Feature/NumberingTest.php`：
  - 情境：舊專案佔用 0002、0003，回收筒裡還有一張佔 0004。
  - 新專案依序拿到 0001、0005、0006、0007。
  - 客戶單：跳過 0001，拿到 0002。

不用改資料庫、不需要跑 SQL。

## 驗證

- 用使用者的檔案，在測試環境模擬正式站的狀況（另一個專案擁有 YC-I2026-0002～0004），走完整流程：
  - 預覽：新建 49、略過 10。
  - 上傳 44 張圖後確認匯入：回 200，建立 49 張，單號 0001、0005～0052。
  - 驗證完已刪除這個暫時測試。
- 全部測試 280 個通過；這次改的檔 pint 通過。

## 補充

失敗的兩次匯入都整批 rollback，正式站沒有留下半套資料。已上傳的 44 張圖是暫存檔，7 天後會被排程清掉。
