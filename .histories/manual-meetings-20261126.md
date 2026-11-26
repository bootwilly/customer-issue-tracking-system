# 操作手冊補會議章節 - 2026-11-26

| 檔案 | 修改 |
|---|---|
| `docs/manual/11-meetings.md` | 新增：第 11 章 會議（含 `<!-- internal -->` 區塊） |
| `docs/manual/img/meeting-*.png` | 新增 10 張截圖（本機示範資料） |
| `docs/manual/manual.json` | 加 `11-meetings: all` |
| `docs/manual/README.md` | 目錄與角色閱讀建議加第 11 章 |
| `docs/manual/01-getting-started.md` | 畫面配置、選單表加「會議」 |
| `docs/manual/08-project-admin.md` | 回收筒、異動紀錄加會議 |
| `docs/manual/09-system-admin.md` | 選項管理加「會議類型」 |
| `docs/manual/10-troubleshooting.md` | 新增 10.8 會議，原回報段改 10.9 |
| `docs/manual/dist/` | 重建 HTML、PDF |

尚未部署正式站。

## 版面修正與部署（2026-11-26 10:30 ～ 10:50）

| 檔案 | 修改 |
|---|---|
| `cits/resources/views/meetings/_item.blade.php` | `$box` 移除 `w-full`；兩個 textarea 補 `w-full`；問題單欄 `w-28` → `w-40` |
| `cits/public/build/` | 重新 build（`ASSET_URL=/mescits`） |
| `docs/manual/img/meeting-show.png`、`meeting-picker.png` | 重拍 |
| `docs/manual/dist/` | 重建 |

已部署正式站。10:45～10:49 因誤傳另一工作中未部署的程式造成 500，10:50:24 前三個檔案已還原（本機檔案未改）。詳見 `SESSION_LOG.md`。
