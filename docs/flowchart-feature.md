# 專案流程圖功能（MD 匯入）規格與待辦

建立：2026-11-25　狀態：核心功能已實作（本機測試 143/143、瀏覽器驗證通過），尚未部署；§10 建議功能 A~E 未做

---

## 1. 已定案需求

| 項目 | 決定 |
|---|---|
| 來源格式 | `.md` 檔（也可貼文字）；` ```mermaid ` / `~~~mermaid` 區塊 render 成流程圖，其餘 Markdown 當說明文字 |
| 範圍 | 每個專案可有多份流程圖文件，每份可上傳新版本（保留歷史） |
| 誰能看 | 專案內所有成員：client_pm、client_view、internal_pm、dev、test（admin 經 Gate::before 自動通過） |
| 誰能上傳/改 | 只有 PM：client_pm、internal_pm（客戶 PM 與內部 PM 都可編輯，彼此可改對方上傳的文件） |

## 2. 權限

`database/seeders/data/workflow.php` → `roles`：

```php
'client_pm'   => [..., [..., 'flow.view', 'flow.manage']],
'client_view' => [..., [..., 'flow.view']],
'internal_pm' => [..., [..., 'flow.view', 'flow.manage']],
'dev'         => [..., [..., 'flow.view']],
'test'        => [..., [..., 'flow.view']],
```

- 正式站：`php deploy/make_upgrade_sql.php` 產生 `INSERT IGNORE permissions / role_has_permissions`，使用者在 phpMyAdmin 執行。
- 部署後必須清 spatie permission cache（同 Excel 匯出那次的一次性腳本）。
- Service 判斷：
  ```php
  public function canView(User $u, int $pid): bool   { return $this->wf->can($u, 'flow.view', $pid); }
  public function canManage(User $u, int $pid): bool { return $this->wf->can($u, 'flow.manage', $pid); }
  ```
  不加 `! $u->isClient()`，因為客戶也要能看/改。

## 3. 資料模型

```php
// migration 2026_11_26_000100_create_project_flows_tables.php
Schema::create('project_flows', function (Blueprint $t) {
    $t->id();
    $t->foreignId('project_id')->constrained()->cascadeOnDelete();
    $t->string('title', 200);
    $t->longText('source_md');                 // 只存原始 MD，不存 render 結果
    $t->unsignedInteger('version')->default(1);
    $t->unsignedSmallInteger('sort')->default(0);
    $t->boolean('is_active')->default(true);   // 停用：一般成員看不到，manager 灰階顯示
    $t->foreignId('updated_by')->nullable()->constrained('users');
    $t->timestamps();
    $t->index(['project_id', 'is_active', 'sort']);
});

Schema::create('project_flow_revisions', function (Blueprint $t) {
    $t->id();
    $t->foreignId('flow_id')->constrained('project_flows')->cascadeOnDelete();
    $t->unsignedInteger('version');
    $t->string('title', 200);
    $t->longText('source_md');
    $t->foreignId('created_by')->nullable()->constrained('users');
    $t->timestamp('created_at');
    $t->unique(['flow_id', 'version']);
});
```

- 每次建立/更新都寫一筆 revision（v1 也寫），`project_flows` 只放最新版，列表查詢不用 join。
- 只存原始 MD 的理由：重新 render 不需遷移資料、可做文字 diff、MCP 可直接讀原文。

`system_settings` 新增：

```php
['flow.max_bytes', '200000'],      // 單份 MD 上限
['flow.max_blocks', '30'],         // 單份最多 mermaid 區塊
```

## 4. 分層與檔案

| 層 | 檔案 | 職責 |
|---|---|---|
| Route | `routes/modules/flow.php` | middleware `web, auth, active, project`，prefix `flows`，name `flows.` |
| Controller | `app/Http/Controllers/Flow/FlowController.php` | 驗證輸入、權限 guard、BusinessException → back()->with('err') |
| Service (BLL) | `app/Services/Flow/FlowService.php` | 解析、驗證、版本號、event log；**不碰 DB** |
| Service | `app/Services/Flow/FlowRenderer.php` | MD → 安全 HTML（commonmark + sanitizer + mermaid placeholder） |
| Repository | `app/Repositories/FlowRepository.php` | project_flows / revisions CRUD |
| Model | `app/Models/ProjectFlow.php`、`ProjectFlowRevision.php` | |
| View | `resources/views/flows/{index,show,preview}.blade.php` | |
| JS | `resources/js/flow/index.js` | mermaid lazy render、語法驗證、錯誤顯示 |
| Test | `tests/Feature/FlowTest.php` | |

修改：`vite.config.js`（新 input）、`deploy/build.py`（ASSET_URL）、`layouts/app.blade.php`（nav）、`SanitizerService`（新增 markdown 白名單方法）、`workflow.php`、`package.json`（`mermaid ^11`）。

## 5. 路由

```php
Route::middleware(['web', 'auth', 'active', 'project'])->prefix('flows')->name('flows.')->group(function () {
    Route::get('/', [FlowController::class, 'index'])->name('index');
    Route::post('/preview', [FlowController::class, 'preview'])->name('preview');          // 新增或更新前預覽，不寫入
    Route::post('/', [FlowController::class, 'store'])->name('store');                     // 新增
    Route::get('/{id}', [FlowController::class, 'show'])->whereNumber('id')->name('show'); // ?v=3 看舊版
    Route::put('/{id}', [FlowController::class, 'update'])->whereNumber('id')->name('update'); // 上傳新版本
    Route::post('/{id}/toggle', [FlowController::class, 'toggle'])->whereNumber('id')->name('toggle');
    Route::get('/{id}/download', [FlowController::class, 'download'])->whereNumber('id')->name('download'); // 下載原始 .md（?v=）
});
```

所有 `{id}` 操作都要驗 `flow.project_id === 目前專案`，否則 404（避免跨專案讀取）。

## 6. 匯入流程（沿用 MES sitemap 的 preview → apply）

1. 表單：`title`（可空，空則取第一個 `# ` heading，再沒有就用檔名去副檔名）、`md_file`（`.md`/`.markdown`/`.txt`，或）`md_text`、`flow_id`（有值＝上傳新版本）。
2. `preview`：伺服器端結構檢查 → render → 回 `flows.preview`，頁面上 JS 逐塊 `mermaid.parse()`：
   - 全部通過才啟用「確認儲存」按鈕；
   - 失敗的區塊顯示「第 N 個流程圖：錯誤訊息（第 L 行）」；
   - 更新模式下同時顯示與目前版本的行數差異摘要。
3. `store` / `update`：伺服器端**重新**做結構檢查（不信任 preview 的 hidden 欄位），寫 flow + revision + event，in UnitOfWork。

伺服器端結構檢查（PHP 沒有 mermaid parser，語法只能前端驗）：

```php
public function parse(string $md, ?string $fallbackTitle): array
{
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    if (! mb_check_encoding($md, 'UTF-8')) throw new BusinessException('檔案必須是 UTF-8 編碼。');
    $md = preg_replace('/^\xEF\xBB\xBF/', '', $md);   // 去 BOM
    if (strlen($md) > (int) $this->wf->setting('flow.max_bytes', '200000')) throw new BusinessException('檔案過大。');
    preg_match_all('/^(```|~~~)mermaid[ \t]*\n(.*?)^\1[ \t]*$/ms', $md, $m);
    if ($m[2] === []) throw new BusinessException('檔案中沒有 mermaid 區塊。');
    if (count($m[2]) > (int) $this->wf->setting('flow.max_blocks', '30')) throw new BusinessException('流程圖區塊過多。');
    $title = preg_match('/^#\s+(.+)$/m', $md, $h) ? trim($h[1]) : (string) $fallbackTitle;
    if ($title === '') throw new BusinessException('請輸入標題。');

    return ['md' => $md, 'title' => mb_substr($title, 0, 200), 'blocks' => count($m[2])];
}
```

## 7. Render 與安全

```php
// FlowRenderer::render(string $md): string
$blocks = [];
$md = preg_replace_callback('/^(```|~~~)mermaid[ \t]*\n(.*?)^\1[ \t]*$/ms', function ($m) use (&$blocks) {
    $blocks[] = $m[2];
    return "\nCITSFLOW".(count($blocks) - 1)."END\n";
}, $md);
$html = (new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]))->convert($md)->getContent();
$html = $this->sanitizer->cleanMarkdown($html);
return preg_replace_callback('~<p>CITSFLOW(\d+)END</p>~', fn ($m) =>
    '<div class="cits-flow" data-index="'.$m[1].'"><pre class="mermaid">'.e($blocks[(int) $m[1]]).'</pre></div>', $html);
```

- `html_input => strip`：MD 內的原生 HTML 一律丟掉。
- `SanitizerService::cleanMarkdown()`：在現有白名單外加 `h1 h4 h5 h6 hr table thead tbody tr th td`，**另開方法**，不動 ticket 用的 `clean()`。
- mermaid：`securityLevel: 'strict'`（預設），關閉 HTML label 與 `click` callback；mermaid 原始碼用 `e()` 放進 `<pre>`，前端讀 `textContent`。
- `img` 沿用 `ImgSrcAttributeSanitizer`（只允許同源/附件），外部圖片會被移除。

前端：

```js
// resources/js/flow/index.js（只在 flows 頁面 @vite，不進 app.js）
import mermaid from 'mermaid';

mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: 'default',
    fontFamily: '"Instrument Sans", "Microsoft JhengHei", "PingFang TC", sans-serif' });

export async function renderAll(root = document) {
    const errors = [];
    let i = 0;
    for (const pre of root.querySelectorAll('.cits-flow pre.mermaid')) {
        const src = pre.textContent;
        const box = pre.parentElement;
        try {
            await mermaid.parse(src);
            const { svg } = await mermaid.render(`cits-flow-${i}`, src);
            box.innerHTML = svg;
        } catch (e) {
            errors.push({ index: i, message: String(e?.message ?? e) });
            box.insertAdjacentHTML('beforeend', `<p class="mt-2 text-sm text-red-600"></p>`);
            box.lastElementChild.textContent = `第 ${i + 1} 個流程圖語法錯誤：${e?.message ?? e}`;
        }
        i++;
    }
    return errors;
}
```

## 8. 必須處理的坑

1. **子路徑下 mermaid 動態 chunk 404**：mermaid v11 每種圖型都用 `import()` 載入；laravel-vite-plugin build 時 base = `${ASSET_URL}/build/`，沒設 ASSET_URL 會去抓 `https://example.com/build/assets/...`。
   修法：`deploy/build.py` 執行 npm build 時帶環境變數：
   ```python
   run(["npm.cmd", "run", "build"], SRC, env={**os.environ, "ASSET_URL": "/mescits"})
   ```
   （`run()` 要加 `env` 參數）。副作用：本機 `public/build` 也會是 `/mescits` base，本機在根路徑跑時流程圖頁的 chunk 會 404 → 本機請用 `npm run dev`，或 build 後再重跑一次不帶 ASSET_URL 的 build。
2. **bundle 大小**：mermaid gzip 後約 600KB+，只在 `flows/show`、`flows/preview` 用 `@push('head') @vite('resources/js/flow/index.js') @endpush` 載入。
3. **伺服器端不能 render**：mermaid-cli 需要 Chromium，共享主機跑不動；Kroki 等外部服務會外洩客戶流程，不採用。→ Excel 匯出無法放圖。
4. **中文字型寬度**：`fontFamily` 要跟版面一致，否則節點寬度計算錯、文字被截。
5. **大型 MD 檔的 PHP 記憶體**：`flow.max_bytes` 200KB 足夠，commonmark 無壓力。
6. **mermaid 版本鎖定**：mermaid 小版本偶有語法相容性變動，`package.json` 鎖 `~11.x`，升級時跑一次既有流程圖回歸（見 §10 建議 E）。

## 9. 測試（FlowTest.php）

- client_view / dev / test 可看列表與內容，不可 preview/store/update/toggle（403）。
- client_pm / internal_pm 可新增、上傳新版本；版本號遞增、revision 筆數正確。
- 沒有 mermaid 區塊、超過大小、非 UTF-8 → BusinessException 訊息。
- 標題取第一個 `#` heading；無 heading 時用檔名。
- 跨專案 id → 404。
- render：`<script>`、`onerror=`、`javascript:` 連結被移除；mermaid 原始碼中的 `<` 被 escape。
- 停用的流程圖：一般成員列表看不到、直接開 show 404；manager 看得到。
- `?v=1` 看舊版、download 回傳原始 MD（`Content-Type: text/markdown; charset=utf-8`）。

## 10. 建議功能（尚未決定是否做）

### A. 流程圖節點連結 MES 頁面 + 未結問題熱點（價值最高，推測）

- 約定：節點 id 或 label 帶 MES 頁面路徑檔名或代碼，例如：
  ```mermaid
  flowchart LR
    WorkOrder[工單建立 WorkOrder.aspx] --> Inspect[檢驗 Inspect.aspx]
  ```
- 實作：render 完後前端掃 SVG `g.node`，取 id（mermaid 產生 `flowchart-WorkOrder-0`）或 label，比對後端提供的 `mes_pages`（`page_file` / `name`）對照表 JSON。
- 比對成功：
  - 節點加連結到 `pm.index?page_id=...`（內部）或 `tickets.index?page_id=...`（客戶）。
  - 節點右上角標「未結問題數」徽章，數量越多顏色越深 → 問題熱點圖。
- 不需要 `securityLevel: loose`（連結由我們自己掛，不經 mermaid click），安全等級不變。
- 需要：`FlowRepository` 或既有 `MesPageRepository` 提供頁面對照、`IssueRepository`/`TicketRepository` 提供「依頁面分組的未結數」查詢（客戶看到的數量只算客戶單）。
- 風險：mermaid 內部 id 格式不是公開 API，升級可能變 → 改比對 label 文字較穩，但 label 可能重複。建議兩者都支援，id 優先。
- 估計：0.5～1 天。

### B. MCP 唯讀工具 `get_project_flows`

- `app/Mcp/Tools/GetProjectFlowsTool.php`：參數 `project_code`、可選 `flow_id`；回傳標題、版本、更新時間、原始 MD。
- 權限：沿用 MCP token 的使用者，需 `flow.view`。
- 用途：AI 回答「某功能的流程是什麼 / 這張單影響流程哪一段」時可讀流程原文。
- 估計：約 30 行 + 1 個測試。

### C. 匯出 SVG / PNG

- 每個流程圖右上角「下載 SVG」：`new Blob([svgEl.outerHTML], {type: 'image/svg+xml'})`。
- PNG：SVG 畫到 `<canvas>` 再 `toBlob('image/png')`；注意字型要先載入、`foreignObject` 在 strict 模式下不會出現所以不會污染 canvas。
- 「下載原始 .md」已在核心功能（`flows.download`）。
- 估計：1～2 小時，純前端。

### D. 版本差異比較

- `show` 頁加「與上一版比較」：`RevisionDiffService::diffLines(explode("\n", $old), explode("\n", $new))`，沿用 `revisions/diff` 的顯示樣式。
- 進階（推測）：兩版圖並排 render，或只 diff mermaid 區塊。
- 估計：2～3 小時。

### E. 其他想到的

- **語法回歸檢查**：升 mermaid 版本前，用 Playwright 把所有流程圖在本機跑一次 `mermaid.parse()`，列出會壞的文件（避免升級後客戶圖突然變紅字）。
- **在問題單引用流程圖**：ticket/issue 內文支援 `[[flow:12]]` 或下拉選「相關流程圖」，show 頁顯示連結。需要新的關聯表 `issue_flow` / `ticket_flow`。
- **流程圖範本**：admin 提供幾份 MD 範本（工單流程、檢驗流程）供 PM 下載改寫。
- **通知**：流程圖新增/改版時通知專案成員（沿用 NotificationService，email 依 `email_notify` opt-out）。

## 11. 部署步驟（實作後）

1. 本機：`npm install`（含 mermaid）、測試全綠。
2. `php deploy/make_upgrade_sql.php` → 使用者在 phpMyAdmin 執行 upgrade SQL（2 張表 + migration 紀錄 + settings + 權限）。
3. `python deploy/build.py`（含 ASSET_URL=/mescits）→ 上傳 app.zip + 解壓 + public/。
4. 一次性腳本清 permission cache。
5. 驗證：`/flows` 未登入 302；登入後 chunk 載入路徑為 `/mescits/build/assets/...`（DevTools Network 無 404）。

## 12. 實作紀錄（2026-11-25）

與規格的差異：

- 多了 `POST /flows/{id}/sort`（列表直接改排序）。
- `FlowService::parse()` 會把內容正規化為 `trim($md)."\n"`：表單送出會被 Laravel `TrimStrings` 修剪，檔案上傳不會；不正規化的話「內容相同」判斷會失準。
- `SanitizerService::cleanMarkdown()` 設 `withMaxInputLength(2_000_000)`：symfony 預設只吃 20000 字元，超過會被截斷（ticket 用的 `clean()` 未改，仍是預設 20000，屬既有行為）。
- mermaid 設 `flowchart.htmlLabels: false`：label 用純 SVG text，不用 foreignObject，安全且之後匯出 PNG 不會污染 canvas。
- 上傳的 `.md` 檔不存成附件，只存文字內容。

驗證：

- `tests/Feature/FlowTest.php` 9 tests；全套 143/143。
- 本機瀏覽器（臨時 sqlite）：預覽 3 塊中 1 塊故意寫錯 → 2 塊 render、錯誤訊息顯示、儲存鈕 disabled；正確 MD 儲存後 show 頁 2 張圖、中文 label 正常、`/build/assets` 無 404。
- `ASSET_URL=/mescits npm run build` 產物中 chunk 路徑為 `/mescits/build/...`，無裸 `/build/assets`。

部署前置：`deploy/out/upgrade-20261125-0531.sql`（2 張表 + migration 紀錄 + 2 settings + flow.view/flow.manage 權限與授權，皆為 CREATE / INSERT IGNORE）。

## 13. 線上繪製（draw.io）（2026-11-25）

決策：

- 線上新增/編輯用**自架 draw.io**（jgraph/drawio v32.7.4，鎖版本），mermaid MD 匯入保留，兩種格式並存。
- 格式欄位 `format`（`drawio` / `mermaid`）建立後不可改；選單文字、順序、啟用與否來自 lookup `flow_format`，程式只認得 `FlowService::HANDLERS`。
- 存檔格式：draw.io「可編輯 SVG」（export `xmlsvg`），一份檔案同時是圖片與原始檔，存在 `source_md`（欄位不改名）。
- 一份流程圖只能一頁（多頁請拆多份），伺服器擋 >1 個 `<diagram>`。
- 圖內不需要連結；顯示用 `<img>`，提供縮放/拖曳。

實作：

| 項目 | 內容 |
|---|---|
| draw.io 靜態檔 | `cits/public/drawio/`（3075 檔，去掉 WEB-INF、META-INF、`*.map`），版本與 sha256 記在 `CITS_VERSION.txt`；`js/PreConfig.js` 改了 base URL 並強制 `stealth=1`、`lockdown=1`（不連 diagrams.net） |
| 編輯器 | `GET /flows/create`、`GET /flows/{id}/edit?v=`，`flows/editor.blade.php` 全螢幕 iframe；`resources/js/flow/editor.js` 走 embed JSON postMessage：`init→load`、`autosave→localStorage 草稿`、`save→export xmlsvg→fetch POST`、`exit` |
| 樂觀鎖 | 表單帶 `base_version`；`update()` 在交易內 `lockForUpdate` 重讀，版本不符回 422「已被其他人更新為第 N 版」，草稿保留 |
| 驗證 | `DrawioParser`：UTF-8、大小（`flow.drawio_max_bytes`=5000000）、擋 `<script/iframe/object/embed/...>`、`on*=`、`javascript:`、DOCTYPE/ENTITY；必須有 `content` mxfile；支援壓縮頁（base64 + raw deflate + URI encode）；抽出節點文字存 `search_text` |
| 深色模式 | draw.io 匯出 `color-scheme: light dark` + `light-dark()`，深色 OS 下 `<img>` 會變黑底白字；存檔時把根節點改成 `color-scheme: light` |
| 顯示 | `GET /flows/{id}/image?v=`：`image/svg+xml` + `CSP: default-src 'none'; ...; sandbox` + nosniff；show 頁 `<img>` 包在 `resources/js/flow/panzoom.js`（自寫，無套件）：滾輪縮放、拖曳、雙擊放大、－/100%/＋/符合；mermaid 圖也套用（Ctrl+滾輪，避免擋住頁面捲動） |
| 搜尋 | 列表 `?q=` 比對標題與 `search_text`（mermaid 存原始 MD，drawio 存節點文字） |
| 下載 | drawio 下載 `標題_vN.drawio.svg`（draw.io 桌面版可直接開啟編輯） |
| 部署 | `python deploy/build.py --drawio` 另外產 `drawio.zip` + `xd_*.php`；一般部署的 `public/` 不含 drawio |

已知限制：

- draw.io 底部仍有「新增頁面」的 +（`pages=0` 只在單頁時隱藏頁籤列表），多頁會在存檔時被擋並提示。
- 視窗寬度小時 draw.io 會收起左側圖形庫（點左上角圖示展開）；手機隱藏編輯按鈕。
- `compressXml` 設定在 embed 匯出時不一定生效，實測存下來是壓縮頁；伺服器兩種都能解。
- 觸控雙指縮放未實作（只有按鈕與拖曳）。

驗證：FlowTest 23 tests（新增 14 個 drawio 情境），全套 157/157；本機瀏覽器實測新增、重新開啟編輯、草稿暫存、存成 v2、版本衝突 422、`<img>` 顯示與縮放拖曳、編輯器載入 13 個資源全部同源且無 404。
