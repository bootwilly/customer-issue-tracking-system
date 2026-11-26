# 更新包套入即送測 + 指定測試人員 (2026-11-26 13:10)

需求：開發管理內，更新包套入問題後，把問題狀態改成待測試，並跳出要指定測試人員。

- `WorkflowService::targetRequiring(scope, from, field)`：找出需要某欄位的轉換目標（需要 package_id 的那條 = 開發中 → 待測試）。不寫死狀態碼。
- `IssueService`
  - `packageCandidateCodes()` 改為「有需要更新包的轉換」的狀態（目前只有開發中）。
  - `addToPackage(..., ?int $testerId)` 每張單走 `transition()`（同單張送測：權限、Test 人員權限檢查、actual_done_at、通知、客戶單鏈鎖），再記 `package_set`。整批同一個交易，任一張失敗全部不變。未選測試人員時，每張單都必須已有 Test 負責人（沿用）。
  - `packageBoard()` 多回傳 `packageTarget`（目標狀態名稱）。
- `IssueRepository::inStatuses()` 帶出 tester。
- `DevService` index 多傳 `testUsers`（有 test.manage 的成員）；`addToPackage` 帶 testerId。
- `DevController::addToPackage` 接 `assignee_test_id`；成功訊息「已套入 N 張問題單並送測。」
- `dev/index.blade.php`
  - 按鈕改「套入並送測（改為待測試）」，按下跳出 `<dialog>` 指定測試人員。
  - 勾選的單有任何一張沒有 Test 負責人時，必須選人；全部都有時可選「沿用各單原本的 Test」。
  - 專案沒有 Test 人員時顯示提示。
  - 候選清單不再排除已在此更新包的單（只看狀態）。
- `tests/Feature/ProgressPackageTest.php` 更新更新包測試。全套 205/205。
- 本機瀏覽器確認對話框置中、未選人時會擋。前端已重建（新 class m-auto）。
