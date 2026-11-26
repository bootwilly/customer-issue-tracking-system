# 第三方軟體授權聲明（THIRD PARTY NOTICES）

本專案（CITS）以 **Apache License 2.0** 授權，全文見根目錄 [`LICENSE`](LICENSE)，著作權聲明見 [`NOTICE`](NOTICE)。
本專案使用或內附的第三方軟體各自保有原授權，本文件說明有哪些第三方軟體、各自的授權，以及**使用、修改、散布本專案時要遵守哪些條件**。

> 本文件是整理說明，不是法律意見。授權內容以各套件附的授權原文為準。

---

## 1. 摘要：會不會被「傳染」？

**不會。** 本專案沒有使用任何會強制整個專案改用相同授權或公開原始碼的強傳染性授權（GPL、AGPL）。

| 情況 | 處理方式 |
|---|---|
| 有多種授權可選的套件 | 本專案的選擇見第 2 節，所選的都不是傳染性授權 |
| 弱傳染性授權（LGPL、MPL） | 只限於該元件本身，不影響本專案其他程式碼，詳見第 3、5 節 |
| 其餘套件 | MIT、ISC、BSD、Apache-2.0、0BSD 等寬鬆授權，只要保留著作權和授權聲明 |

---

## 2. 有多種授權可選的套件：本專案的選擇

| 套件 | 可選的授權 | **本專案的選擇** |
|---|---|---|
| nette/schema（PHP） | BSD-3-Clause / GPL-2.0-only / GPL-3.0-only | **BSD-3-Clause** |
| nette/utils（PHP） | BSD-3-Clause / GPL-2.0-only / GPL-3.0-only | **BSD-3-Clause** |
| DOMPurify（JS，打包進前端） | MPL-2.0 / Apache-2.0 | **Apache-2.0** |

---

## 3. draw.io（內附於 `cits/public/drawio/`）

本專案內附自架的 draw.io，作為流程圖編輯器。

| 項目 | 內容 |
|---|---|
| 版本 | v32.7.4 |
| 來源 | https://github.com/jgraph/drawio/releases/download/v32.7.4/draw.war（sha256 見 `cits/public/drawio/CITS_VERSION.txt`） |
| 授權 | **Apache License 2.0**，全文見 `cits/public/drawio/LICENSE` |
| 著作權 | Copyright (c) JGraph Ltd |
| **本專案的修改** | 打包時移除了 `WEB-INF`、`META-INF` 和 `*.map`；**修改了 `js/PreConfig.js`**（標記為 CITS 的那幾行，用來設定成離線、自架模式）。其他檔案都沒有改動 |

draw.io 裡面還包含以下幾個授權不同的部分，**請特別注意**：

### 3.1 libavoid-js：LGPL-2.1（`cits/public/drawio/js/libavoid-js/`）
- 用途：流程圖連接線的自動繞線。授權全文見同目錄的 `LICENSE`。
- **本專案沒有修改它**，它也是獨立的 JS 檔，沒有和其他程式碼合併成同一個檔案。因此 LGPL 的義務**只限於這個函式庫本身**，不會影響本專案或你自己的程式。
- 散布時你要做到：
  1. 保留它的 `LICENSE` 檔；
  2. 讓收到的人可以取得這個函式庫的原始碼（上游專案是公開的）；
  3. 讓使用者可以把它換成其他版本，所以**請不要把它和其他程式碼合併、混淆後打包成同一個檔案**。
- **如果你修改了 libavoid-js 本身**，修改後的版本要用 LGPL-2.1 公開原始碼。

### 3.2 範本（`cits/public/drawio/templates/`）：CC BY 4.0
- 授權全文見同目錄的 `LICENSE`。
- 使用或散布時要**標示出處**（draw.io / JGraph Ltd）、附上授權連結，如果有修改也要註明。

### 3.3 圖示集和圖形庫（`img/`、`shapes/`、`stencils/`）：使用限制
- 條款原文見各目錄的 `LICENSE`。大意是：這些圖示集、圖形庫，以及任何衍生物（包括轉換格式、描摹、相似圖像、以這些圖示為參考或訓練資料產生的 AI 圖像），**未經書面許可，不得用作 Atlassian 產品、Atlassian Marketplace 或其外掛生態系的軟體資產，也不得隨這些產品散布或併入其中。**
- 使用者用 draw.io 畫好、匯出的圖（圖片或文件）**不受這個限制**。
- 本專案不是 Atlassian 產品，不受影響。**如果你要把本專案或其中的 draw.io 用在 Atlassian 相關產品，請先取得 JGraph 的書面許可。**

---

## 4. 各類授權的義務（散布本專案或部署後的成品時）

| 授權 | 主要義務 | 傳染性 |
|---|---|---|
| **Apache-2.0**（本專案、draw.io、DOMPurify 等） | 附上授權全文和 NOTICE；修改過的檔案要註明有修改；不得使用授權人的商標；授權中包含專利授權條款 | 無 |
| **MIT / ISC / 0BSD / BSD-2/3-Clause** | 在副本或重要部分保留著作權和授權聲明；BSD-3 另外規定不得用作者名義背書 | 無 |
| **LGPL-2.1**（libavoid-js） | 見 3.1：保留授權檔、提供原始碼、讓使用者可以替換；修改它本身要公開 | 只限於該函式庫 |
| **MPL-2.0**（lightningcss，只在建置時使用） | 修改到的 MPL 檔案要公開；本專案沒有散布它 | 只限於檔案層級 |
| **CC BY 4.0**（draw.io 範本） | 標示出處、附授權連結、註明修改 | 無 |

**實務上要注意**
- **原始碼 repo** 不包含 `vendor/`、`node_modules/`，這些套件是安裝時才從官方來源下載，也會附上各自的授權檔。
- **部署後的網站**：瀏覽器會載入打包後的 `public/build`（內含第 6 節的 JS 和 CSS 套件），伺服器上的 `vendor/` 也有 PHP 套件。你部署或散布成品時，請一併保留本文件和 `licenses/` 目錄。這兩個目錄裡的授權全文，就是用來滿足上面「保留授權聲明」的義務。

---

## 5. 只在開發和建置時使用（不會散布）

Vite、Tailwind CSS 編譯器、lightningcss（MPL-2.0）、laravel-vite-plugin、concurrently、Pest、PHPUnit、Faker、Laravel Pail 等。
這些只在開發者電腦上使用，不會出現在部署的成品裡（Tailwind 產生的 CSS 中含有的 preflight 樣式例外，列在第 6 節）。

---

## 6. 打包進前端、會送到瀏覽器的 JS／CSS 套件（162 個）

授權全文：[`licenses/THIRD_PARTY_LICENSES_JS.txt`](licenses/THIRD_PARTY_LICENSES_JS.txt)

| 套件 | 版本 | 授權 | 來源 |
|---|---|---|---|
| @antfu/install-pkg | 2.1.0 | MIT | git+https://github.com/antfu-collective/install-pkg.git |
| @braintree/sanitize-url | 7.1.2 | MIT | git+https://github.com/braintree/sanitize-url.git |
| @chevrotain/types | 11.1.2 | Apache-2.0 | git://github.com/Chevrotain/chevrotain.git |
| @iconify/types | 2.0.0 | MIT | https://github.com/iconify/iconify.git |
| @iconify/utils | 3.1.7 | MIT | https://github.com/iconify/iconify.git |
| @mermaid-js/parser | 1.2.1 | MIT | https://github.com/mermaid-js/mermaid.git |
| @tiptap/core | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-blockquote | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-bold | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-bullet-list | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-code | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-code-block | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-color | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-document | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-dropcursor | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-gapcursor | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-hard-break | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-heading | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-horizontal-rule | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-image | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-italic | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-link | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-list | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-list-item | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-list-keymap | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-ordered-list | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-paragraph | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-strike | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-table | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-text | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-text-style | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extension-underline | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/extensions | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/pm | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @tiptap/starter-kit | 3.35.0 | MIT | https://github.com/ueberdosis/tiptap |
| @types/d3 | 7.4.3 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-array | 3.2.2 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-axis | 3.0.6 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-brush | 3.0.6 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-chord | 3.0.6 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-color | 3.1.3 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-contour | 3.0.6 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-delaunay | 6.0.4 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-dispatch | 3.0.7 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-drag | 3.0.7 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-dsv | 3.0.7 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-ease | 3.0.2 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-fetch | 3.0.7 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-force | 3.0.10 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-format | 3.0.4 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-geo | 3.1.1 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-hierarchy | 3.1.7 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-interpolate | 3.0.4 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-path | 3.1.1 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-polygon | 3.0.2 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-quadtree | 3.0.6 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-random | 3.0.4 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-scale | 4.0.9 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-scale-chromatic | 3.1.0 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-selection | 3.0.13 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-shape | 3.2.0 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-time | 3.0.4 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-time-format | 4.0.3 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-timer | 3.0.2 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-transition | 3.0.9 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/d3-zoom | 3.0.9 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @types/geojson | 7946.0.16 | MIT | https://github.com/DefinitelyTyped/DefinitelyTyped.git |
| @upsetjs/venn.js | 2.0.0 | MIT | https://github.com/upsetjs/venn.js.git |
| @vue/reactivity | 3.5.46 | MIT | git+https://github.com/vuejs/core.git |
| @vue/shared | 3.5.46 | MIT | git+https://github.com/vuejs/core.git |
| alpinejs | 3.17.5 | MIT | https://github.com/alpinejs/alpine.git |
| cose-base | 1.0.3 | MIT | git+https://github.com/iVis-at-Bilkent/cose-base.git |
| cytoscape | 3.35.0 | MIT | https://github.com/cytoscape/cytoscape.js.git |
| cytoscape-cose-bilkent | 4.1.0 | MIT | https://github.com/cytoscape/cytoscape.js-cose-bilkent.git |
| cytoscape-fcose | 2.2.0 | MIT | https://github.com/iVis-at-Bilkent/cytoscape.js-fcose.git |
| cose-base | 2.2.0 | MIT | git+https://github.com/iVis-at-Bilkent/cose-base.git |
| layout-base | 2.0.1 | MIT | git+https://github.com/iVis-at-Bilkent/layout-base.git |
| d3 | 7.9.2 | ISC | https://github.com/d3/d3.git |
| d3-array | 3.2.5 | ISC | https://github.com/d3/d3-array.git |
| d3-axis | 3.0.1 | ISC | https://github.com/d3/d3-axis.git |
| d3-brush | 3.0.1 | ISC | https://github.com/d3/d3-brush.git |
| d3-chord | 3.0.2 | ISC | https://github.com/d3/d3-chord.git |
| d3-color | 3.1.1 | ISC | https://github.com/d3/d3-color.git |
| d3-contour | 4.0.3 | ISC | https://github.com/d3/d3-contour.git |
| d3-delaunay | 6.0.6 | ISC | https://github.com/d3/d3-delaunay.git |
| d3-dispatch | 3.0.2 | ISC | https://github.com/d3/d3-dispatch.git |
| d3-drag | 3.0.1 | ISC | https://github.com/d3/d3-drag.git |
| d3-dsv | 3.0.2 | ISC | https://github.com/d3/d3-dsv.git |
| commander | 7.2.0 | MIT | https://github.com/tj/commander.js.git |
| d3-ease | 3.0.2 | BSD-3-Clause | https://github.com/d3/d3-ease.git |
| d3-fetch | 3.0.2 | ISC | https://github.com/d3/d3-fetch.git |
| d3-force | 3.0.1 | ISC | https://github.com/d3/d3-force.git |
| d3-format | 3.1.3 | ISC | https://github.com/d3/d3-format.git |
| d3-geo | 3.1.2 | ISC | https://github.com/d3/d3-geo.git |
| d3-hierarchy | 3.1.3 | ISC | https://github.com/d3/d3-hierarchy.git |
| d3-interpolate | 3.0.2 | ISC | https://github.com/d3/d3-interpolate.git |
| d3-path | 3.1.1 | ISC | https://github.com/d3/d3-path.git |
| d3-polygon | 3.0.2 | ISC | https://github.com/d3/d3-polygon.git |
| d3-quadtree | 3.0.2 | ISC | https://github.com/d3/d3-quadtree.git |
| d3-random | 3.0.2 | ISC | https://github.com/d3/d3-random.git |
| d3-sankey | 0.12.3 | BSD-3-Clause | https://github.com/d3/d3-sankey.git |
| d3-array | 2.12.1 | BSD-3-Clause | https://github.com/d3/d3-array.git |
| d3-path | 1.0.9 | BSD-3-Clause | https://github.com/d3/d3-path.git |
| d3-shape | 1.3.7 | BSD-3-Clause | https://github.com/d3/d3-shape.git |
| internmap | 1.0.1 | ISC | https://github.com/mbostock/internmap.git |
| d3-scale | 4.0.3 | ISC | https://github.com/d3/d3-scale.git |
| d3-scale-chromatic | 3.1.1 | ISC | https://github.com/d3/d3-scale-chromatic.git |
| d3-selection | 3.0.1 | ISC | https://github.com/d3/d3-selection.git |
| d3-shape | 3.2.1 | ISC | https://github.com/d3/d3-shape.git |
| d3-time | 3.1.1 | ISC | https://github.com/d3/d3-time.git |
| d3-time-format | 4.1.1 | ISC | https://github.com/d3/d3-time-format.git |
| d3-timer | 3.0.2 | ISC | https://github.com/d3/d3-timer.git |
| d3-transition | 3.0.2 | ISC | https://github.com/d3/d3-transition.git |
| d3-zoom | 3.0.1 | ISC | https://github.com/d3/d3-zoom.git |
| dagre-d3-es | 7.0.14 | MIT | git+https://github.com/tbo47/dagre-es.git |
| dayjs | 1.11.23 | MIT | https://github.com/iamkun/dayjs.git |
| delaunator | 5.1.0 | ISC | https://github.com/mapbox/delaunator.git |
| dompurify | 3.5.0 | Apache-2.0（二選一：MPL-2.0 / Apache-2.0，本專案選 Apache-2.0） | git://github.com/cure53/DOMPurify.git |
| es-toolkit | 1.53.2 | MIT | https://github.com/toss/es-toolkit.git |
| fastdom | 1.0.12 | MIT | git://github.com/wilsonpage/fastdom.git |
| hachure-fill | 0.5.2 | MIT | git+https://github.com/pshihn/hachure-fill.git |
| iconv-lite | 0.6.3 | MIT | git://github.com/ashtuchkin/iconv-lite.git |
| import-meta-resolve | 4.2.0 | MIT | wooorm/import-meta-resolve |
| internmap | 2.0.3 | ISC | https://github.com/mbostock/internmap.git |
| katex | 0.16.47 | MIT | https://github.com/KaTeX/KaTeX.git |
| commander | 8.3.0 | MIT | https://github.com/tj/commander.js.git |
| khroma | 2.1.0 | MIT（package.json 未標示，依 license 檔） | github:fabiospampinato/khroma |
| layout-base | 1.0.2 | MIT | git+https://github.com/iVis-at-Bilkent/layout-base.git |
| linkifyjs | 4.4.0 | MIT | git+https://github.com/nfrasser/linkifyjs.git |
| lodash-es | 4.18.1 | MIT | lodash/lodash |
| marked | 16.4.2 | MIT | git://github.com/markedjs/marked.git |
| mermaid | 11.17.2 | MIT | https://github.com/mermaid-js/mermaid |
| orderedmap | 2.1.1 | MIT | git+https://github.com/marijnh/orderedmap.git |
| package-manager-detector | 1.9.0 | MIT | git+https://github.com/antfu-collective/package-manager-detector.git |
| path-data-parser | 0.1.0 | MIT | git+https://github.com/pshihn/path-data-parser.git |
| points-on-curve | 0.2.0 | MIT | git+https://github.com/pshihn/bezier-points.git |
| points-on-path | 0.2.1 | MIT | git+https://github.com/pshihn/points-on-path.git |
| prosemirror-changeset | 2.4.6 | MIT | git+https://code.haverbeke.berlin/prosemirror/prosemirror-changeset.git |
| prosemirror-commands | 1.7.2 | MIT | git+https://code.haverbeke.berlin/prosemirror/prosemirror-commands.git |
| prosemirror-dropcursor | 1.8.4 | MIT | git+https://code.haverbeke.berlin/prosemirror/prosemirror-dropcursor.git |
| prosemirror-gapcursor | 1.4.1 | MIT | git://github.com/prosemirror/prosemirror-gapcursor.git |
| prosemirror-history | 1.5.2 | MIT | git+https://code.haverbeke.berlin/prosemirror/prosemirror-history.git |
| prosemirror-inputrules | 1.7.0 | MIT | git+https://code.haverbeke.berlin/prosemirror/prosemirror-inputrules.git |
| prosemirror-keymap | 1.2.3 | MIT | git://github.com/prosemirror/prosemirror-keymap.git |
| prosemirror-model | 1.25.13 | MIT | git+https://code.haverbeke.berlin/prosemirror/prosemirror-model.git |
| prosemirror-schema-list | 1.6.1 | MIT | git+https://code.haverbeke.berlin/prosemirror/prosemirror-schema-list.git |
| prosemirror-state | 1.4.4 | MIT | git://github.com/prosemirror/prosemirror-state.git |
| prosemirror-tables | 1.8.5 | MIT | https://github.com/ProseMirror/prosemirror-tables.git |
| prosemirror-transform | 1.12.7 | MIT | git+https://code.haverbeke.berlin/prosemirror/prosemirror-transform.git |
| prosemirror-view | 1.43.1 | MIT | git+https://code.haverbeke.berlin/prosemirror/prosemirror-view.git |
| robust-predicates | 3.0.3 | Unlicense | https://github.com/mourner/robust-predicates.git |
| rope-sequence | 1.3.4 | MIT | git+https://github.com/marijnh/rope-sequence.git |
| roughjs | 4.6.6 | MIT | git+https://github.com/pshihn/rough.git |
| rw | 1.3.3 | BSD-3-Clause | http://github.com/mbostock/rw.git |
| safer-buffer | 2.1.2 | MIT | git+https://github.com/ChALkeR/safer-buffer.git |
| strictdom | 1.0.1 | MIT | git://github.com/wilsonpage/strictdom.git |
| stylis | 4.4.0 | MIT | https://github.com/thysultan/stylis.js |
| tailwindcss | 4.3.5 | MIT | https://github.com/tailwindlabs/tailwindcss.git |
| tinyexec | 1.4.0 | MIT | git+https://github.com/tinylibs/tinyexec.git |
| ts-dedent | 2.3.0 | MIT | https://github.com/tamino-martinius/node-ts-dedent.git |
| uuid | 14.0.3 | MIT | https://github.com/uuidjs/uuid.git |
| w3c-keyname | 2.2.8 | MIT | git+https://github.com/marijnh/w3c-keyname.git |

---

## 7. 正式環境使用的 PHP 套件（composer install --no-dev，82 個）

授權全文：[`licenses/THIRD_PARTY_LICENSES_PHP.txt`](licenses/THIRD_PARTY_LICENSES_PHP.txt)

| 套件 | 版本 | 授權 | 來源 |
|---|---|---|---|
| brick/math | 1.0.0 | MIT | https://github.com/brick/math.git |
| carbonphp/carbon-doctrine-types | 3.2.1 | MIT | https://github.com/CarbonPHP/carbon-doctrine-types.git |
| dflydev/dot-access-data | v3.0.3 | MIT | https://github.com/dflydev/dflydev-dot-access-data |
| doctrine/inflector | 2.1.1 | MIT | https://www.doctrine-project.org/projects/inflector.html |
| doctrine/lexer | 3.0.3 | MIT | https://www.doctrine-project.org/projects/lexer.html |
| dragonmantank/cron-expression | v3.6.1 | MIT | https://github.com/dragonmantank/cron-expression.git |
| egulias/email-validator | 4.0.5 | MIT | https://github.com/egulias/EmailValidator |
| fruitcake/php-cors | v1.4.0 | MIT | https://github.com/fruitcake/php-cors |
| graham-campbell/result-type | v1.2.0 | MIT | https://github.com/GrahamCampbell/Result-Type.git |
| guzzlehttp/guzzle | 8.6.0 | MIT | https://github.com/guzzle/guzzle.git |
| guzzlehttp/promises | 3.2.0 | MIT | https://github.com/guzzle/promises.git |
| guzzlehttp/psr7 | 3.6.0 | MIT | https://github.com/guzzle/psr7.git |
| guzzlehttp/uri-template | v2.0.1 | MIT | https://github.com/guzzle/uri-template.git |
| laravel/framework | v13.43.0 | MIT | https://laravel.com |
| laravel/mcp | v1.2.1 | MIT | https://github.com/laravel/mcp |
| laravel/prompts | v0.3.27 | MIT | https://github.com/laravel/prompts.git |
| laravel/serializable-closure | v2.2.0 | MIT | https://github.com/laravel/serializable-closure.git |
| laravel/tinker | v3.2.0 | MIT | https://github.com/laravel/tinker.git |
| league/commonmark | 2.10.7 | BSD-3-Clause | https://commonmark.thephpleague.com |
| league/config | v1.2.0 | BSD-3-Clause | https://config.thephpleague.com |
| league/flysystem | 3.36.0 | MIT | https://github.com/thephpleague/flysystem.git |
| league/flysystem-local | 3.35.3 | MIT | https://github.com/thephpleague/flysystem-local.git |
| league/mime-type-detection | 1.17.0 | MIT | https://github.com/thephpleague/mime-type-detection.git |
| league/uri | 7.10.0 | MIT | https://uri.thephpleague.com |
| league/uri-interfaces | 7.10.0 | MIT | https://uri.thephpleague.com |
| monolog/monolog | 3.13.0 | MIT | https://github.com/Seldaek/monolog |
| nesbot/carbon | 3.15.1 | MIT | https://carbonphp.github.io/carbon/ |
| nette/schema | v1.4.0 | BSD-3-Clause（三選一：BSD-3-Clause / GPL-2.0-only / GPL-3.0-only，本專案選 BSD-3-Clause） | https://doc.nette.org/schema |
| nette/utils | v4.1.6 | BSD-3-Clause（三選一：BSD-3-Clause / GPL-2.0-only / GPL-3.0-only，本專案選 BSD-3-Clause） | https://nette.org |
| nikic/php-parser | v5.9.0 | BSD-3-Clause | https://github.com/nikic/PHP-Parser.git |
| nunomaduro/termwind | v2.4.1 | MIT | https://github.com/nunomaduro/termwind.git |
| openspout/openspout | v5.15.3 | MIT | https://github.com/openspout/openspout |
| phpoption/phpoption | 1.10.0 | Apache-2.0 | https://github.com/schmittjoh/php-option.git |
| psr/clock | 1.0.0 | MIT | https://github.com/php-fig/clock |
| psr/container | 2.0.2 | MIT | https://github.com/php-fig/container |
| psr/event-dispatcher | 1.0.0 | MIT | https://github.com/php-fig/event-dispatcher.git |
| psr/http-client | 1.0.3 | MIT | https://github.com/php-fig/http-client |
| psr/http-factory | 1.1.0 | MIT | https://github.com/php-fig/http-factory.git |
| psr/http-message | 2.0 | MIT | https://github.com/php-fig/http-message |
| psr/log | 3.0.2 | MIT | https://github.com/php-fig/log |
| psr/simple-cache | 3.0.0 | MIT | https://github.com/php-fig/simple-cache.git |
| psy/psysh | v0.12.24 | MIT | https://psysh.org |
| ramsey/collection | 2.1.1 | MIT | https://github.com/ramsey/collection.git |
| ramsey/uuid | 4.10.0 | MIT | https://github.com/ramsey/uuid.git |
| spatie/laravel-package-tools | 1.93.3 | MIT | https://github.com/spatie/laravel-package-tools |
| spatie/laravel-permission | 8.5.0 | MIT | https://github.com/spatie/laravel-permission |
| symfony/clock | v8.1.9 | MIT | https://symfony.com |
| symfony/console | v8.1.11 | MIT | https://symfony.com |
| symfony/css-selector | v8.1.10 | MIT | https://symfony.com |
| symfony/deprecation-contracts | v3.8.1 | MIT | https://symfony.com |
| symfony/error-handler | v8.1.10 | MIT | https://symfony.com |
| symfony/event-dispatcher | v8.1.5 | MIT | https://symfony.com |
| symfony/event-dispatcher-contracts | v3.8.1 | MIT | https://symfony.com |
| symfony/finder | v8.1.11 | MIT | https://symfony.com |
| symfony/html-sanitizer | v8.1.11 | MIT | https://symfony.com |
| symfony/http-foundation | v8.1.11 | MIT | https://symfony.com |
| symfony/http-kernel | v8.1.11 | MIT | https://symfony.com |
| symfony/mailer | v8.1.10 | MIT | https://symfony.com |
| symfony/mime | v8.1.10 | MIT | https://symfony.com |
| symfony/polyfill-ctype | v1.37.0 | MIT | https://symfony.com |
| symfony/polyfill-intl-grapheme | v1.43.3 | MIT | https://symfony.com |
| symfony/polyfill-intl-idn | v1.43.0 | MIT | https://symfony.com |
| symfony/polyfill-intl-normalizer | v1.43.0 | MIT | https://symfony.com |
| symfony/polyfill-mbstring | v1.43.3 | MIT | https://symfony.com |
| symfony/polyfill-php80 | v1.43.0 | MIT | https://symfony.com |
| symfony/polyfill-php82 | v1.43.0 | MIT | https://symfony.com |
| symfony/polyfill-php84 | v1.43.0 | MIT | https://symfony.com |
| symfony/polyfill-php85 | v1.44.0 | MIT | https://symfony.com |
| symfony/polyfill-php86 | v1.43.0 | MIT | https://symfony.com |
| symfony/polyfill-time | v1.42.0 | MIT | https://symfony.com |
| symfony/polyfill-uuid | v1.43.0 | MIT | https://symfony.com |
| symfony/process | v8.1.10 | MIT | https://symfony.com |
| symfony/routing | v8.1.11 | MIT | https://symfony.com |
| symfony/service-contracts | v3.8.1 | MIT | https://symfony.com |
| symfony/string | v8.1.10 | MIT | https://symfony.com |
| symfony/translation | v8.1.11 | MIT | https://symfony.com |
| symfony/translation-contracts | v3.8.1 | MIT | https://symfony.com |
| symfony/uid | v8.1.9 | MIT | https://symfony.com |
| symfony/var-dumper | v8.1.10 | MIT | https://symfony.com |
| tijsverkoyen/css-to-inline-styles | v2.4.0 | BSD-3-Clause | https://github.com/tijsverkoyen/CssToInlineStyles |
| vlucas/phpdotenv | v5.8.1 | BSD-3-Clause | https://github.com/phpdotenv/phpdotenv.git |
| voku/portable-ascii | 2.1.1 | MIT | https://github.com/voku/portable-ascii |

---

## 8. 更新本文件

套件升級後（`composer.lock` 或 `package-lock.json` 有變動），要重新產生這份清單和 `licenses/` 裡的檔案。產生方式：
- PHP：讀取 `composer licenses --no-dev` 的結果，以及 `vendor/<套件>/LICENSE*`。
- JS：從 `package-lock.json` 找出 `alpinejs`、`mermaid`、`@tiptap/*` 以及它們所有的相依套件（再加上 `tailwindcss`），讀取各自的 `package.json` 和 license 檔。
- draw.io 升級時，要更新第 3 節的版本、來源、雜湊值和修改說明，並換上新版的 `LICENSE`。
