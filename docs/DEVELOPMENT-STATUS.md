# 開發進度與驗收紀錄

更新：2026-09-08。狀態只能使用 `未開始`、`進行中`、`待驗收`、`完成`、`受阻`。

目前已完成 P00 本機基線、P01 Laravel + Vue + SQLite + Boost 基礎、P02 三個部會資料 adapter 與快照備援，以及 P03 伺服器權威戰鬥引擎與平衡鎖版；13 關內容、正式視聽與 Hostinger 實機驗證仍依後續階段進行。

| 階段 | 狀態 | 驗收依據／下一步 |
|---|---|---|
| 文件基線 | 完成 | 七份文件與 README 入口；後續以使用者補充持續修訂 |
| 初批開放素材候選下載 | 完成 | 4 圖＋4 音效、兩份授權、manifest；圖片已目視，音效待試聽，尚未整合，不代表 P04／P06 完成 |
| P00 版本與環境 | 完成 | [P00 報告](phase-reports/P00.md)：本機版本、PHP 擴充、遷移邊界與 Pages 保護已驗證；Hostinger 實機能力保留至 P09 |
| P01 框架與 Boost | 完成 | [P01 報告](phase-reports/P01.md)、[Boost 紀錄](BOOST-SETUP.md)：Laravel/Vue/SQLite、lockfile、測試、建置與 Boost 全選驗證通過 |
| P02 資料轉接 | 完成 | [P02 報告](phase-reports/P02.md)、[資料契約](DATA-CONTRACT.md)：三來源本機實連成功，逾時／403／429／HTML 假成功／缺欄位／過期／零值缺值均有測試；正式主機解析樣本待 P09 前補齊 |
| P03 戰鬥引擎 | 待驗收 | [P03 報告](phase-reports/P03.md)、[平衡鎖版](BALANCE.md)：確定性引擎、事件契約、六個 `/api/v1` 介面、去重與版本語意、三關策略矩陣；真人試玩與效能壓測留給 P08 |
| P04 第 1 關切片 | 未開始 | 首批實際圖片、音效及完整遊玩；前端只播 `events`，不重算傷害 |
| P05 全 13 關 | 未開始 | 差異機制、存檔與逐關可解證據 |
| P06 正式視聽 | 未開始 | 全部必要素材、來源與演出 |
| P07 結局複盤 | 未開始 | 26 份關卡結局樣本及全章終幕 |
| P08 全面驗收 | 未開始 | 策略矩陣、真人試玩與主機前驗證 |
| P09 正式部署 | 未開始 | Hostinger 實測、備份還原及 README 切換 |

## 最新實作基線

- `rules_version` 鎖在 `1.0.0`（`config/game.php`）；數值與公式以 [BALANCE.md](BALANCE.md) 為準，改動必須升版否則舊局重播會對不上。
- 戰鬥引擎在 `app/Domain/Game/`，**完全沒有亂數**：城市行為由關卡預告表決定，相同輸入必得相同事件與結局。
- 六個 `/api/v1` 介面掛在 `web` middleware group，仍受工作階段與 CSRF 保護（實測無 token POST 回 419）。
- 匿名存檔為 `campaigns`／`runs`／`run_actions`；`(run_id, action_id)` 唯一索引保證重送回放原結果，寫入採條件版本更新（SQLite 的 `lockForUpdate()` 是 no-op，不能依賴）。
- 施招端點限流每分鐘 60 次，超過回 429 並帶 `Retry-After`；SQLite `busy_timeout` 設 5000 毫秒。
- 同一次結算產生的事件全部掛在該行動所屬回合，含回合推進；依 `turn` 分組的重播與演出可以直接使用。
- 只有 `POST /api/v1/runs` 會建立匿名戰役；唯讀端點不寫入。
- `php artisan game:simulate` 產生策略矩陣；驗收門檻已釘進 `tests/Feature/Game/StrategyMatrixTest.php`。
- `config/opendata.php` 是三個部會來源的唯一清單；resource URL 由 `data.gov.tw` 資料集 API 的 `distribution` 取得，不在程式內拼湊。
- `data_snapshots` 只存正規化結果（實測 2.2–5.7 KB／筆，上游原始為 0.8–283 KB）；發布為原子操作，失敗保留最近有效快照。
- 無有效快照時使用 `database/fixtures/opendata/*.demo.json`，`quality` 固定 `demo`，UI 必須顯示「示範情境」。
- 排程走 `routes/console.php` 三條 `withoutOverlapping` 定義，Hostinger 只需一條 cron 呼叫 `schedule:run`。
- Laravel `13.30.1`、Vue `3.5.42`、Vite `8.2.2`、TypeScript `5.9.3`、Laravel Boost `2.7.1` 已安裝並提交 lockfile；TypeScript 使用 5.9.3 是因 Vue 型別檢查工具目前與 TypeScript 7 不相容。
- 根目錄 `public/` 是 Laravel 入口；`resources/js/app.ts` 是新版 Vue mount point；`public/build/` 是本機／正式建置產物並被 Git 忽略。
- `api-probe/` 已有自己的 package 設定；Node 探測測試仍可用，不能把探測器當正式部署服務。
- `docs/` 四個 GitHub Pages 核心檔案的 SHA-256 已於 P00／P01 報告記錄，且本階段未改動。

## 規劃當日已取得的事實

- 工作樹基準 commit：`333a34c`；現有程式是靜態遊戲及 Node API 探測器。本階段新增的 Laravel 骨架與 Vue shell 以未來里程碑 commit 記錄。
- 查閱並安裝 Laravel `13.30.1`、Vue `3.5.42`、Laravel Boost `2.7.1`；來源及鎖定策略見[技術規格](TECHNICAL-SPEC.md)與[P01 報告](phase-reports/P01.md)。
- 本機 PATH 可用 PHP `8.4.25`、Composer `2.8.5`、Node `24.16.0`；`pdo_sqlite`、`sqlite3`、`curl`、`mbstring`、`openssl` 等必要擴充已確認。
- Hostinger 部會資料可連結論引用 2026-09-07 repo 調查；本次沒有遠端主機工作階段，沒有重新宣稱正式 Laravel adapter 已驗證成功。

## 每次交接必填

| 項目 | 內容 |
|---|---|
| 本次指派階段 | P03 可重播的戰鬥引擎與平衡初稿 |
| 執行者及日期 | Claude Opus 5（透過 Claude Code），2026-09-08 |
| 基準／交付 commit 或未提交變更 | 基準 `bebff8a`；交付見 P03 報告 |
| 階段報告相對路徑 | `docs/phase-reports/P03.md` |
| 已通過項目 | 107 個 PHPUnit 測試、策略矩陣驗收門檻、真實 HTTP 端到端（含 CSRF 419 與重送回放）、Pint、typecheck、build、probe |
| 尚未驗證／受阻原因 | 真人試玩與難度標籤（P08）、效能壓測、UI 與演出（P04）、其餘 10 關（P05）、快照清理的對局引用保護 |
| 下一位執行者第一步 | 讀 `docs/BALANCE.md` 與 P03 報告，執行 `php artisan opendata:refresh` 後開始 P04 |

## 決策變更紀錄

| 日期 | 決策 | 理由與影響 |
|---|---|---|
| 2026-09-08 | 建立 1.0 規格；本次只寫文件 | 使用者先要求可分階段交接的實際開發文件 |
| 2026-09-08 | SQLite + file cache/session + PHP cron | 配合儲存量小與 Hostinger 共享空間；取代舊 API 報告的 Redis 建議 |
| 2026-09-08 | 13 使徒採原創虛構災變意象 | 保留致敬概念並建立可辨識的自有美術；不引用既有使徒名錄／造型 |
| 2026-09-08 | 加入免費開源／開放授權素材搜尋及下載 | 使用者追加授權；已下載 Kenney CC0 特效圖與音效小型候選，保留原創圖片要求 |
| 2026-09-08 | 根目錄改為 Laravel 13 + Vue TypeScript，Node 探測器移至 `api-probe/` | 正式目標是 Hostinger PHP 共享空間；保留舊探測器作為資料來源與主機能力證據 |
| 2026-09-08 | Vite 產物固定在 `public/build`，GitHub Pages 繼續使用 `docs/` | 避免新版框架建置破壞初階文件審查入口 |
| 2026-09-08 | Boost 2.7.1 所有可偵測 agent、guidelines、skills、MCP 與 Cloud skill 非互動全選 | `scripts/configure-boost.php` 會檢查版本、路徑安全、渲染失敗與實際產物 |
| 2026-09-08 | 水庫水情不輸出蓄水百分比，改用同水庫自我比較的 `storage_index` | 45501 資源沒有滿水位容量與蓄水百分比欄位；不得捏造蓄水率 |
| 2026-09-08 | 年度／月報型來源以資料涵蓋年度判定 stale，不套 48 小時門檻 | 園區用水落後 > 1 民國年、國土利用落後 > 2 民國年；避免把正常年報當壞資料 |
| 2026-09-08 | `SnapshotRepository::prune()` 保留但不排程 | `runs` 表在 P03 才建立，清理前必須排除仍被對局引用的 `snapshot_id`，否則破壞重播 |
| 2026-09-08 | 遊戲設計初稿數值全面重調並鎖成 `rules_version 1.0.0` | 初稿在任何策略下都無法通關（全表 0% 勝率）；調整項目與理由見 [BALANCE.md §6](BALANCE.md) |
| 2026-09-08 | `rules_version 1.0.0` 不使用亂數 | 遊戲設計允許首版無隨機；讓重播不必保存亂數狀態，P07 反事實比較也不會因多抽一次亂數而失真 |
| 2026-09-08 | `/api/v1` 掛在 `web` middleware group 而非 Laravel 預設的 `api:` 路由 | 匿名存檔綁 HttpOnly session，少了 session 與 CSRF 等於誰都能改別人的局 |
