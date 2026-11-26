# 部署可攜性修正與 README (2026-11-26)

- `deploy/build.py`：核心目錄改成可設定（`deploy.local.json` 的 `app_dir` 或環境變數 `CITS_APP_DIR`，預設 `app/mescits`），套用到 public/index.php 的路徑和解壓程式；`ASSET_URL` 改由 `app_url` 的子目錄自動推算；npm 指令改為跨平台。原作者的設定產生的結果不變（已驗證 index.php 與解壓程式路徑）。
- `deploy/make_upgrade_sql.php`：`deploy/out/install.sql` 改為非必要；新增 `--applied-file=<檔案>`（phpMyAdmin 匯出的 migrations 清單，自動擷取名稱）與 `--no-baseline`；三種來源都沒有時，提示怎麼查詢後結束（exit 1）。已測試三種情境。
- `docs/DEPLOYMENT_GUIDE.md`：3.1、3.2 改用 `ADMIN_INITIAL_PASSWORD='<自訂密碼>'`（沒設就不會有 admin），說明 SQL 不在 repo；第 5 節說明已套用清單的三種來源。
- `AGENT_DEPLOY.md`：路線 C 加上 `app_dir` 設定、自行產生 install SQL 的指令、升級 SQL 的用法。
- 新增根目錄 `README.md`：功能、架構（技術、分層、資料驅動、流程、目錄）、三種部署方式、部署前須知、授權注意事項（本專案 Apache-2.0、第三方、draw.io 特殊條件）、文件索引。
