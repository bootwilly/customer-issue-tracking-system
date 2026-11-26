# 手冊更新：協作功能、客戶進度、更新包（2026-11-26 14:00）

只改手冊，未改程式，未部署。

| 檔案（docs/manual/） | 修改內容 |
|---|---|
| 01-getting-started.md | 登入導向依權限；1.6 新增客戶單／內部單通知對象表 |
| 02-workflow.md | 開發中→待測試補 Test 負責人；2.6 改名「自動規則」，加補充資料、相依 |
| 03-client.md | View 角色可關注／存查詢；清單新欄位與篩選；重複提示；處理進度；新增 3.9 |
| 04-pm.md | 五個計數、客戶單範圍、結案欄、標籤篩選；新增 4.11 補充資料、4.12 標籤、4.13 相依 |
| 05-dev.md | 移除 5.7 過期附註；新增 5.8 更新包 |
| 06-test.md | 我的查詢、重複提示、新發現單不可設需等待 |
| 07-common.md | 新增 7.8 關注、7.9 我的查詢、7.10 重複問題提示 |
| 09-system-admin.md | 選項管理加內部單標籤；新增 9.8 背景設定與排程 |
| 10-troubleshooting.md | 新增 10.9、10.10；回報段改為 10.11 |
| img/ | 重拍畫面（flows-editor 未重拍）；新增 client-needinfo、client-similar、issue-similar、saved-queries、pm-needinfo、issue-tags、issue-deps、dev-package、dev-package-dialog |
| dist/ | 重建 manual.html、CITS-manual.pdf |

截圖在 scratchpad 的程式副本（13:41 版本）執行，使用拋棄式 SQLite。手冊測試 4/4（副本內）。

## 追加（2026-11-26 14:15）：撤回送測、會議篩選與匯出

對應功能尚未部署（待執行 upgrade-20261126-0559.sql），這批手冊要跟功能一起上。

| 檔案 | 修改內容 |
|---|---|
| 01 | 內部通知表加「撤回送測」 |
| 02 | 狀態圖、流轉表加 待測試→開發中 |
| 05 | 5.8 移出行為改寫 |
| 06 | 撤回送測提醒 |
| 07 | 7.4 會議匯出 |
| 10 | 移出更新包新訊息、會議篩選／匯出問題 |
| 11 | 11.1 匯出權限、11.2 篩選與內部清單截圖、新增 11.8 |
| img | 重拍 dev-package、meeting-list、meeting-client-list、meeting-client、meeting-client-done、meeting-show、meeting-mine |

## 追加（2026-11-26 15:04）：版面修正（本機，未部署）

| 檔案 | 修改內容 |
|---|---|
| 02-workflow.md | 2.2 拆成兩張橫向流程圖；2.4 拆成「開單到派工」「開發到上線」兩張橫向狀態圖（原本縮到 54%／47% 看不清） |
| img/forgot.png | 重拍：原圖是「系統未啟用郵件」畫面，與內文步驟不符 |
| dist/ | 重建 |

- 2026-11-26 15:06 已部署：02-workflow.md、img/forgot.png。
