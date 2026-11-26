# Apache-2.0 授權、第三方聲明、Agent 部署文件 (2026-11-26)

- 新增 `LICENSE`（Apache License 2.0 全文）、`NOTICE`（著作權暫寫 "The CITS Authors"，draw.io 修改說明、libavoid LGPL、templates CC BY 4.0、圖示集 Atlassian 限制、授權選擇）。
- `cits/composer.json` license 改為 Apache-2.0。
- 新增 `THIRD_PARTY_NOTICES.md`：傳染性摘要、授權選擇（nette/* BSD-3-Clause、DOMPurify Apache-2.0）、draw.io 細節、各授權義務、建置用工具、前端打包套件 162 個、PHP 正式環境套件 82 個的清單。
- 新增 `licenses/THIRD_PARTY_LICENSES_JS.txt`、`licenses/THIRD_PARTY_LICENSES_PHP.txt`：從本機 node_modules / vendor 擷取授權全文（離線產生）。alpinejs、fastdom、strictdom 的 npm 套件未附 license 檔，依 package.json 的 MIT 與作者補上標準 MIT 全文。
- 新增 `AGENT_DEPLOY.md`：給下載者的 agent 部署指南（可複製的指令、需求、本機試用、VPS、cPanel、驗證、開發規則、常見問題、授權）。
