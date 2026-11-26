# 編輯器繪圖區（跨圖標註）

時間：2026-11-26（Asia/Taipei）

- 新增 `cits/resources/js/editor/drawing.js`：全螢幕 draw.io 畫布。選取的圖片由上往下排列並鎖定為底圖；預設紅框、不填色、紅色直線箭頭；儲存匯出內含繪圖資料的 PNG（xmlpng），可再開啟編輯。
- `cits/resources/js/editor/index.js`：圖片節點加 `drawing` 屬性（輸出 `data-drawing="1"`）；工具列「繪圖」：選到繪圖就重開，否則把選取範圍內所有圖片放進新畫布（沒選圖＝空白畫布）；儲存後上傳並取代那些圖片（放在第一張的位置，中間文字保留）；雙擊繪圖重開。
- `cits/resources/views/components/editor.blade.php`：工具列加「繪圖」按鈕（手機隱藏）。
- `cits/app/Services/SanitizerService.php`：img 允許 `data-drawing`。
- `cits/tests/Feature/EditorTableTest.php`：新增 `data-drawing` 保留測試。
- 驗證：Pest 168/168；瀏覽器實測兩張截圖各畫一圈並拉跨圖箭頭、儲存成一張圖、雙擊重開仍可編輯。未部署。

## 補充：圖片勾選視窗（2026-11-26）

- `cits/resources/js/editor/drawing.js`：新增 `pickImages()`，列出內文所有圖片縮圖（依內文順序，選取範圍內的預先勾選），可勾多張、按「空白畫布」或取消；勾選順序以編號顯示。
- `cits/resources/js/editor/index.js`：按「繪圖」改為先開勾選視窗；只勾一張繪圖＝重新編輯。
- `cits/resources/views/components/editor.blade.php`：按鈕提示文字更新。
- 驗證：瀏覽器實測勾兩張合成、只取代被勾的圖；只勾繪圖可重新編輯。未部署。
