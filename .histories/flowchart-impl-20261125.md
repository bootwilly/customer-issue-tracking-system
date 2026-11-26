# 流程圖功能實作 - 2026-11-25

新增：
- cits/database/migrations/2026_11_26_000100_create_project_flows_tables.php
- cits/app/Models/ProjectFlow.php、ProjectFlowRevision.php
- cits/app/Repositories/FlowRepository.php
- cits/app/Services/Flow/FlowService.php、FlowRenderer.php
- cits/app/Http/Controllers/Flow/FlowController.php
- cits/routes/modules/flow.php
- cits/resources/views/flows/{index,show,preview,_doc,_upload}.blade.php
- cits/resources/js/flow/index.js
- cits/tests/Feature/FlowTest.php

修改：
- cits/app/Services/SanitizerService.php：抽出 build()，新增 cleanMarkdown()（多 h1/h4-6/hr/table/del，input 上限 2,000,000）；clean() 行為不變。
- cits/database/seeders/data/workflow.php：flow.view（5 角色）、flow.manage（client_pm、internal_pm）；settings flow.max_bytes=200000、flow.max_blocks=30。
- cits/resources/views/layouts/app.blade.php：nav 加「流程圖」（can flow.view）。
- cits/vite.config.js：input 加 resources/js/flow/index.js。
- cits/package.json / package-lock.json：mermaid ^11.17.2（devDependencies）。
- deploy/build.py：run() 支援 env；npm build 帶 ASSET_URL=/mescits。

產出：deploy/out/upgrade-20261125-0531.sql

## 追加（2026-11-25）
- cits/package.json：mermaid 由 ^11.17.2 改為 ~11.17.2（使用者要求），package-lock.json 同步。
- 已部署正式站。
