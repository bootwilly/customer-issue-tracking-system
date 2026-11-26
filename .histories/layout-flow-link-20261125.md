# 流程圖連結移到頂列專案選擇器後面

時間：2026-11-25（Asia/Taipei）

- `cits/resources/views/layouts/app.blade.php`：從第二列選單移除「流程圖」；在頂列「專案」下拉後面加上「流程圖」連結（已選專案且有 `flow.view` 才顯示，在 /flows 底下時高亮）。
- `cits/tests/Feature/FlowTest.php`：導覽測試改為檢查連結在專案選擇器之後、第二列選單之前。
- 驗證：Pest 164/164。未部署。
