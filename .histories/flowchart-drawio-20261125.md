# 流程圖線上繪製（draw.io）實作紀錄

時間：2026-11-25 21:30–22:10（Asia/Taipei）

## 新增

- `cits/public/drawio/`：draw.io v32.7.4 靜態檔（來源 GitHub jgraph/drawio release `draw.war`，sha256 `caf78463...be42f`），移除 WEB-INF、META-INF、`*.map`；`CITS_VERSION.txt` 記錄來源。
- `cits/database/migrations/2026_11_27_000100_add_format_to_project_flows.php`（與正式站手動執行的 SQL 相同）。
- `cits/app/Services/Flow/DrawioParser.php`
- `cits/resources/views/flows/editor.blade.php`
- `cits/resources/js/flow/editor.js`、`view.js`、`panzoom.js`

## 修改

- `cits/public/drawio/js/PreConfig.js`：`DRAWIO_BASE_URL`／`DRAWIO_VIEWER_URL`／`DRAWIO_LIGHTBOX_URL` 指向自架路徑，強制 `stealth=1`、`lockdown=1`。
- `cits/app/Services/Flow/FlowService.php`：`formats()`、`formatLabel()`、`parseDrawio()`、`prepare()`；`create()` 多 `$format`；`update()` 多 `$baseVersion` 並在交易內鎖列比對版本；寫入 `format`、`search_text`；`filename()` 依格式給 `.drawio.svg`。
- `cits/app/Repositories/FlowRepository.php`：`list()` 加關鍵字搜尋與 `format` 欄位、`findForUpdate()`、revisions 帶 `format`。
- `cits/app/Http/Controllers/Flow/FlowController.php`：`create`、`edit`、`image`；store/update 支援 JSON 回應與 `format`、`base_version`；preview 擋 drawio 流程圖；download 依格式。
- `cits/routes/modules/flow.php`：`flows.create`、`flows.edit`、`flows.image`。
- `cits/resources/views/flows/index.blade.php`（搜尋、格式欄、新增選單）、`show.blade.php`（drawio 用 `<img>` + 縮放、線上編輯按鈕）、`_upload.blade.php`、`preview.blade.php`（帶 `format=mermaid`）、`_doc.blade.php`（縮放容器樣式）。
- `cits/resources/js/flow/index.js`：mermaid 圖套用縮放（Ctrl+滾輪）。
- `cits/vite.config.js`：新增 `flow/view.js`、`flow/editor.js` 入口。
- `cits/database/seeders/data/workflow.php`：lookup `flow_format`（drawio、mermaid）、setting `flow.drawio_max_bytes`。
- `cits/tests/Feature/FlowTest.php`：新增 14 個 drawio 測試情境。
- `deploy/build.py`：`public/` 排除 drawio；`--drawio` 產生 `drawio.zip` 與 `xd_*.php` 解壓腳本。
- `docs/flowchart-feature.md`：新增 §13。

## 驗證

- Pest 全套 157/157。
- 本機瀏覽器：新增、重新開啟編輯、草稿暫存、存成 v2、版本衝突 422、深色模式修正、縮放拖曳、編輯器資源全部同源無 404。
