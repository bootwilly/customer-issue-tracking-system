# 編輯器插入表格

時間：2026-11-25（Asia/Taipei）

- `cits/package.json` / `package-lock.json`：新增 `@tiptap/extension-table` ^3.35.0。
- `cits/resources/js/editor/index.js`：加入 TableKit（關閉欄寬拖拉）；表格選單指令（插入 3×3、插入／刪除列欄、標題列、合併／分割、刪除表格）；游標不在表格內時停用相關選項。
- `cits/resources/views/components/editor.blade.php`：工具列新增「表格…」下拉。
- `cits/app/Services/SanitizerService.php`：允許 table、thead、tbody、tr、th、td（只留 colspan、rowspan）；`clean()` 輸入上限 20000 → 2,000,000 字元（修正長內容被截斷）。
- `cits/app/Services/Export/HtmlToText.php`：表格每列轉一行，儲存格以「 | 」分隔（Excel 匯出用）。
- `cits/resources/css/app.css`：`.prose` 表格框線樣式。
- `cits/tests/Feature/EditorTableTest.php`：新增 3 個測試。
- 驗證：Pest 167/167；瀏覽器實測插入表格、插入欄。未部署。
