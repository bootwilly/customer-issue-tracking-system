# 線上操作手冊（/manual）- 2026-11-26

使用者要求把線上文件部署到網站，登入後可看。

| 檔案 | 修改 |
|---|---|
| `cits/app/Services/ManualService.php` | 新增：讀 Markdown、依帳號類型過濾章節／段落／截圖 |
| `cits/app/Http/Controllers/Manual/ManualController.php` | 新增 |
| `cits/routes/modules/manual.php` | 新增：`/manual/{chapter?}`、`/manual/img/{file}`（auth + active） |
| `cits/resources/views/manual/show.blade.php` | 新增 |
| `cits/resources/views/layouts/app.blade.php` | 右上加「操作手冊」連結 |
| `cits/tests/Feature/ManualTest.php` | 新增 |
| `deploy/build.py` | 打包時把 `docs/manual` 複製到 `resources/manual` |
| `docs/manual/manual.json` | 新增：章節可見對象 |
| `docs/manual/*.md` | 加 `<!-- internal -->` 標記、1.4 換圖並加手冊列、重拍截圖 |
| `docs/manual/build.php` | 產出 HTML 前移除標記行 |

已部署正式站（單檔上傳，65 個新檔 + route + layout），未重 build 前端。
