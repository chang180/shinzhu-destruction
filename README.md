# 我的反派學院：超認真毀滅新竹計畫

世外高人｜2026 新竹縣智慧沙盒創新計畫參賽原型。

## 新版開發計畫（2026-09-08）

預計完整重製為最新版穩定 Laravel + Vue、SQLite 與 Hostinger PHP 共享空間上的 **13 關原創使徒策略遊戲**。P01 已完成 Laravel 13.30.1、Vue 3.5.42、Vite 8.2.2、TypeScript 5.9.3、SQLite 與 Laravel Boost 2.7.1 基礎；P02 已完成三個中央部會資料 adapter、正規化快照與示範情境備援；P03 已完成伺服器權威戰鬥引擎、事件契約與平衡鎖版。13 關內容、正式視聽與 Hostinger 實機驗證仍按階段進行。

- [分階段開發計畫](docs/DEVELOPMENT-PLAN.md)：P00～P09 依賴、交付與驗收，可逐階段派給其他 AI。
- [技術規格](docs/TECHNICAL-SPEC.md)：版本、Boost 非互動全選、SQLite、資料來源及 Hostinger 部署。
- [資料契約](docs/DATA-CONTRACT.md)：三個部會來源的正規化快照欄位、單位、品質門檻、失敗分類與備援。
- [平衡鎖版](docs/BALANCE.md)：`rules_version`、技能表、傷害公式、情境修正換算與策略矩陣驗收。
- [13 關遊戲設計](docs/GAME-DESIGN.md)：策略難度、施招、城市防守及玩家勝利複盤。
- [美術與音效規格](docs/ART-AUDIO-SPEC.md)、[素材來源與下載紀錄](docs/ASSET-SOURCES.md)：原創生成與免費開放素材搭配。
- [AI 派工與交接](docs/AI-HANDOFF.md)、[開發進度](docs/DEVELOPMENT-STATUS.md)：可複製派工文字與實際狀態。
- [Boost 安裝紀錄](docs/BOOST-SETUP.md)：非互動全選的版本、agent 矩陣、產物核對與限制。
- [P00 基線報告](docs/phase-reports/P00.md)、[P01 基礎報告](docs/phase-reports/P01.md)、[P02 資料報告](docs/phase-reports/P02.md)、[P03 引擎報告](docs/phase-reports/P03.md)：各階段可重現的驗收證據。

## Phase 1 已完成的新版基礎

- Laravel 13.30.1 位於 repo 根目錄，使用 SQLite、file cache、file session、sync queue；`.env.example` 不含秘密，正式主機的資料庫檔案須放在 `public` 之外的持久路徑。
- Vue 3.5.42 + TypeScript 5.9.3 + Vite 8.2.2 已接到 Laravel Blade 頁面殼；`public/build` 是新版 Laravel 入口的建置輸出，沒有寫入 `docs/`。
- Laravel Boost 2.7.1 已用 `scripts/configure-boost.php` 非互動安裝所有可偵測 agent、guidelines、skills、MCP 與 Cloud skill；生產環境仍須 `composer install --no-dev` 並設定 `BOOST_ENABLED=false`。
- 舊 Node 連線探測器已隔離到 `api-probe/`，原本的資料來源報告與測試仍可重現；它不是正式 Laravel 佈署入口。

## Phase 2 已完成的資料串接

- 三個 adapter 位於 `app/Services/OpenData/`，對應水利署水庫水情、國科會園區用水、內政部國土利用；下載網址由 `data.gov.tw` 資料集 API 的 `distribution` 取得，寫在 `config/opendata.php`。
- 正規化快照存進 SQLite 的 `data_snapshots`，只保留遊戲需要的欄位與單位，每筆 2～6 KB；發布是原子操作，抓取失敗保留最近一次有效快照。
- 品質分為 `fresh`／`stale`／`demo`／`unavailable` 並逐來源顯示；沒有有效快照時使用 `database/fixtures/opendata/` 的示範情境，必須標示為示範情境。
- 資料是有限幅度的遊戲情境輸入，**不是災害預測**：不推算水庫蓄水率、不把公園綠地面積當熱島量測、不把園區用水當全縣即時用水。詳見[資料契約](docs/DATA-CONTRACT.md)。
- 更新命令：`php artisan opendata:refresh`、`php artisan opendata:status`；排程由 `routes/console.php` 定義，Hostinger 只需一條 cron 呼叫 `php artisan schedule:run`。

## Phase 3 已完成的戰鬥引擎

- 規則只有一份，位於 `app/Domain/Game/`；`rules_version` 鎖在 `1.0.0`，數值與公式以[平衡鎖版](docs/BALANCE.md)為準。
- **完全沒有亂數**：城市行為由關卡預告表決定，相同輸入必得相同事件與結局，重播不需保存亂數狀態。
- 六個 `/api/v1` JSON 介面掛在 `web` middleware group，仍受工作階段與 CSRF 保護；匿名存檔綁 HttpOnly session。
- 同一個 `action_id` 重送回放原結果不重新結算；版本過期回 409，規則不合法回 422 且不消耗回合或惡意。
- 每則事件含 `before`／`delta`／`after`／`reason_code`／`cue_id`，足以解釋傷害、護盾、印記、抗性、修復與勝負；前端只播事件，不重算。
- `php artisan game:simulate` 跑（關卡 × 情境 × 策略 × seed）矩陣。實測：規劃與貪心策略全數通關，合法隨機 0～15%，單系連按與舊版「三系集印記再放終招」套路全部 0%。
- P03 只實作三個代表關卡驗證規則；其餘 10 關在 P05。機器策略勝率不是真人體驗證據，難度標籤等 P08 實測後命名。

### 新版本機驗證

```sh
composer install
npm ci
php artisan migrate --force
php artisan opendata:refresh
php artisan game:simulate --seeds=20
npm run typecheck
npm run build
php artisan test --compact
npm run probe:test
npm run probe:build
```

### 兩個發布入口

正式 Laravel 應用使用根目錄的 `public/` 與 `public/build/`，後續依 Hostinger PHP 共享空間能力部署。GitHub Pages 仍固定發布 `main:/docs`；`docs/index.html`、`docs/app.js`、`docs/game.js`、`docs/style.css` 是獨立的初階審查 MVP，Vite 不會覆蓋或依賴它。

## 靜態遊戲 MVP

12 回合內將虛構城市韌性降到零。三系普通技能造成少量傷害、累積印記；三系各滿三枚後終招可通關。連續三種不同技能觸發共鳴，城市每三回合修復。包含勝負戰報、永續解說、提示、重玩、鍵盤操作與行動版配置。

所有數值為遊戲平衡設定，非官方風險、真實環境預測或政策成效。本版未串接即時資料或 AI；教授解說採固定文本。來源連結列在頁面底部，作為後續資料整合方向。

介面為純 CSS／原生 JS 呈現，無圖片素材與框架依賴：核心區塊為 HP 環形進度與粒子軌道、技能卡依水／熱／土地三系配色、終招印記滿載時有動態光效，深色風格搭配漸層背景與玻璃感面板。這段內容描述 GitHub Pages 的歷史審查版本，不代表新版 Laravel 遊戲已完成。

## GitHub Pages

發布來源為 `main` 分支的 `/docs`。網站入口： https://chang180.github.io/shinzhu-destruction/

無需安裝或建置，HTML、CSS、JavaScript 直接由 Pages 提供。中文字型為可選的 Google Fonts，無法載入時使用系統字型。

## 驗證

`php artisan test --compact`、`npm run typecheck`、`npm run build`、`npm run probe:test`、`npm run probe:build`

PHP 測試涵蓋三個資料 adapter 的正規化、缺值與零值處理、過期判定，以及逾時、403、429、HTML 假成功、超量回應等受控失敗與備援；另涵蓋戰鬥引擎的結算順序、抗性與破綻邊界、重送去重、版本衝突、擁有者隔離與策略矩陣門檻。`tests/*.test.cjs` 則涵蓋靜態原型的可通關路線、亂放終招失敗、印記消耗、連攜與修復、10,000 場固定種子的隨機策略。這些是規則與資料處理測試，不等同真人使用者測試。

## Hostinger 主機端 API 連線實驗

repo 的 `api-probe/` 保留可獨立啟動的 Node.js 服務，用於驗證 Hostinger 主機能否取得新竹縣公開資料。使用 `npm --prefix api-probe start`，入口仍是根目錄 `server.js`；完整步驟與結果判讀見 [api-probe/README.md](api-probe/README.md)。GitHub Pages 仍只發布 `docs/` 遊戲。

**實測結論（2026-09-07，見 [api-probe/HOSTINGER-RESULT.md](api-probe/HOSTINGER-RESULT.md)）：** 新竹縣政府自有機房（`dip.hsinchu.gov.tw`、`ws.hsinchu.gov.tw`、`www.hsinchu.gov.tw`）從 Hostinger 主機連線逾時，型態指向該機房對海外來源 IP 有邊界限制；同一台主機呼叫中央氣象署開放資料平台（`opendata.cwa.gov.tw`）則連線正常，證明並非 Hostinger 出站被擋，也不是串接方式的問題，差異在目的地網路。

進一步盤點後（見 [api-probe/DATA-SOURCES-FEASIBILITY.md](api-probe/DATA-SOURCES-FEASIBILITY.md)），確認遊戲頁面原本列出的三個 `data.gov.tw` 資料集，其實際資源都掛在中央部會自己的開放資料平台（經濟部水利署、內政部國土測繪中心、國科會新竹科學園區管理局），而非新竹縣機房，從 Hostinger 全部可連、可用，且內容確實涵蓋新竹縣（水庫水情含寶山第二水庫、永和山水庫；國土利用含新竹縣 13 個鄉鎮市）。後續資料串接應走這些部會平台，避免直接呼叫新竹縣自有機房。

## AI 使用與署名

AI 共創：**GPT-6 Astra（透過 OpenAI Codex）**。本靜態原型的遊戲規則草案、核心程式、介面、固定文案與測試由 GPT-6 Astra 協助生成；**Claude Sonnet 5（透過 Claude Code）** 協助完成 Hostinger 主機連線實測、`data.gov.tw` 資料來源可行性盤點，以及遊戲頁面（`docs/`）的視覺改版；新版開發文件與開放素材來源整理由 OpenAI Codex 協助完成；**Claude Opus 5（透過 Claude Code）** 協助完成 P02 的三個中央部會資料 adapter、正規化快照契約、備援 fixture，以及 P03 的戰鬥引擎、事件契約、HTTP 介面與平衡鎖版。張建文提供提案、創意方向與成果確認。參賽者需檢核並依競賽簡章如實揭露。本次開發文件放在 `docs/`，與既有 Pages 發布樹共存；含個人資料的報名附件及私密設定不納入公開文件。
