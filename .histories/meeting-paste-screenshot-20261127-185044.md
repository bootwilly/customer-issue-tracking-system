# 會議討論事項：貼上截圖

時間：2026-11-27 18:50（Asia/Taipei）。未提交、未部署。

## 原因

使用者在手機上只看到「＋ 圖片」選檔框，以為只能上傳檔案。手機的「長按 → 貼上」在一般文字框貼不出圖片。

## 修改

- `cits/resources/views/meetings/_item-images.blade.php`：
  - 選檔框換成主要按鈕「📋 貼上截圖」（`data-image-paste`）。
  - 選檔改成次要連結「或選擇圖片檔」。
- `cits/resources/views/meetings/_item-image.blade.php`：「標記」改成較醒目的「✏️ 標記」按鈕。
- `cits/resources/views/meetings/show.blade.php`：「討論事項」標題旁加上：
  - 「貼上後直接標記」勾選（`data-auto-annotate`）。
  - 電腦版提示文字。
- `cits/resources/js/meeting/items.js`：
  - `clipboardImages()`：在點擊時用 `navigator.clipboard.read()` 讀剪貼簿裡的圖片。沒有權限、瀏覽器不支援，或剪貼簿沒有圖片時，改用 `pasteDialog()`：跳出一個可貼上的框，接 Ctrl+V 或手機鍵盤的貼上。
  - `addFiles(files, pasted)`：一次只貼一張，且勾了「貼上後直接標記」時，上傳後直接開啟 draw.io。勾選狀態存在 localStorage 的 `cits:meeting:auto-annotate`。
  - 在文字框 Ctrl+V 貼圖也算「貼上」，同樣適用直接標記。
- `docs/manual/11-meetings.md`：說明貼上截圖、權限詢問、手機做法和直接標記。

後端、資料表、API 都沒改。

## 驗證

- 會議和手冊相關測試 31 個通過。
- 用暫存 SQLite 站台，在手機尺寸（375×812）實測：
  - 按「貼上截圖」，讀剪貼簿被拒時有出現貼上框。
  - 在框裡模擬手機鍵盤貼上：圖片上傳並綁定，事項顯示「已儲存」。
  - 勾選「貼上後直接標記」後，在結論欄貼上：上傳後自動開啟 draw.io，勾選狀態有記住。
- 沒測到：真機上 `clipboard.read()` 直接成功的路徑。測試瀏覽器不允許寫入剪貼簿，要在 Android、iOS 實機確認。
