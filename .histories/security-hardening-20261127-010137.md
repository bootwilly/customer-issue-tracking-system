# Security hardening - 2026-11-27

需求：(1) 依帳號計算的登入次數限制（主機代理會採用使用者自己送的 X-Forwarded-For，IP 可以被偽造）；(3) 登入紀錄頁說明 IP 僅供參考；附件類型限定；編輯器防 HTML／JavaScript 攻擊。

- cits/app/Services/AuthService.php：除了「帳號加 IP」的 key，再加 `login-account|<帳號>`（login.account_max_attempts 10、login.account_decay_minutes 15，設 0 關閉）；被這個 key 鎖住時，登入紀錄的 detail 記 per_account。
- cits/app/Services/PasswordResetService.php：忘記密碼的申請次數也依帳號計算（`pwd-forgot-account|<帳號>`）。
- cits/resources/views/admin/auth-logs/index.blade.php：說明文字改成「IP 僅供參考，可被偽造」。
- 附件類型：seed 和新 migration 2026_12_02_000100_widen_attachment_types（只在值還是舊預設時才更新）改成 png,jpg,jpeg,gif,webp,mp4,webm,mov,pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,log,json,md,zip,rar,7z。
  - cits/resources/views/components/editor.blade.php：「附加檔案」的選檔視窗加上 accept。
  - AttachmentService：錯誤訊息會列出可以上傳的類型。
- XSS 防護：
  - 新的 Blade 指令 @richtext（AppServiceProvider），在顯示時再跑一次 SanitizerService::clean。client/show、issues/show、meetings/show、pm/ticket 共 13 處 {!! !!} 都改用它。flows/_doc 維持原樣：它在顯示時就已經 sanitize 過，而且之後還加上 mermaid 區塊。
  - SanitizerService 註冊成 singleton。
  - 新 middleware SecurityHeaders（web group）：CSP object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'，以及 nosniff、X-Frame-Options SAMEORIGIN、Referrer-Policy。
  - AttachmentController：PDF 以外的附件加上 CSP sandbox。
- 測試 tests/Feature/SecurityHardeningTest.php（4 個）；全部測試 230/230 通過。
- 升級 SQL：deploy/out/upgrade-20261126-1700.sql。
