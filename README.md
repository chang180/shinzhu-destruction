# 我的反派學院：超認真毀滅新竹計畫

世外高人｜2026 新竹縣智慧沙盒創新計畫參賽原型。

## 目前進度（2026-10-07，最終版）

**線上版：`rules_version 7.0.0`，已在 Hostinger 正式站運作，即為本次參賽的最終版本**（程式對應 `07d884e`；首頁與 `GET /api/v1/levels` 回 HTTP 200，`.env` 403、`vendor/` 404，production、Debug 關閉）。技術棧：Laravel 13.30.1、Vue 3.5.42 + TypeScript 5.9.3、Vite 8.2.2、SQLite，Hostinger PHP 共享空間，不需 Node 常駐程序、Redis 或 queue worker。

這是一款**限時卡牌策略遊戲**：玩家是反派學生，要用五張手牌、每回合 30 秒的決策窗口，把虛構城市的韌性降到零。**3 關主線＋2 關進階挑戰**，第 3 關通關即可收手結束計畫，也可以接受進階畢業考。

### 已經做到的事

- **伺服器權威、完全沒有亂數的戰鬥引擎**：相同輸入必得相同事件與結局，重播不需保存亂數狀態；舊版本的局只能唯讀與重播，不會被新規則改寫。
- **五關各有獨立機制**：可打斷的修復、系別輪替護盾、需求脈衝、鏡射護盾、核心重整倒數；第 2、4 關後可挑牌組獎勵（兩選一替換、牌組維持 15 張）。
- **三幕關卡結構（P10）**：每一關都分成三幕，戰鬥畫面、開局簡報與戰報都會顯示目前第幾幕、本幕目標與下一幕條件；第 5 關的第三幕由局面觸發，介面會標成「條件幕」。
- **難度曲線有證據**：五關難度指數在 7.0.0 首次完全單調遞增（0.1854 → 0.3081 → 0.3967 → 0.5365 → 0.6107，每關至少 +0.05）。發布矩陣是 27 情境 × 全部合法牌組 × 300 seeds＝**567,000 局**，並以 `DifficultyProgressionTest` 釘成必要回歸；離線 solver 證明 **81,000 格全部可解**（未解 0、未知 0）。
- **23 張原創插畫（WebP）**，來源與雜湊有 manifest，依關卡與事件載入；兩套獨立結局（主線收手／進階終幕）的文案、畫面與音效俱全。
- **戰後複盤**：戰報以伺服器事件說明核心變化、最後一擊與最多三條原因，並提供規則式反事實比較（換掉一次決策，交給模擬策略接手續局）。
- **易用性**：必要字級 16px，卡牌有用途與目標說明；同一張實體牌不能連續留牌。
- **中央部會資料作為情境輸入**：水利署水庫水情、國科會園區用水、內政部國土利用，經正規化快照與品質標示（`fresh`／`stale`／`demo`／`unavailable`）進入遊戲；**這是有限幅度的遊戲修正，不是災害預測**。
- **真人回饋驅動過兩次改版**：2026-09-09～10 的試玩回饋（在地招式、緊張感、卡牌主畫面、關卡數）促成 P04 限時手牌與三關主線＋兩關進階；之後玩過的人反映字太小、看不懂卡牌與結算，促成 P08 易用性改版與留牌限制。
- **品質檢查**：314 個後端測試、65 個前端邏輯測試、TypeScript、Pint 與建置皆通過。

### 試玩驗證：以隨機模擬取代真人試玩

最終驗收不再安排逐人真人試玩，改用可重跑的隨機模擬（使用者決策，見 [P10-7 §6.3、§6.4](docs/phase-reports/P10-7.md)）。

- **新手世代**：`php artisan game:playtest` 讓 10 位模擬玩家（亂打、只打一系、固定循環、貪心、會讀預告五種新手側寫）從第 1 關依序闖關，每關最多重試 12 次。只會亂打或單系連按的玩家全部卡在第 1 關；固定循環能過第 1 關但卡在第 2 關；貪心與會讀預告的玩家可以走到後段。教學關確實會擋下完全不懂機制的打法。
- **後關更難**：世代的平均嘗試次數有倖存者偏差（只有有能力的玩家才走得到後關），所以另外用發布矩陣，讓七種模擬玩家**各自獨立**打每一關：

| 模擬玩家 | 第 1 關 | 第 2 關 | 第 3 關 | 第 4 關 | 第 5 關 |
|---|---:|---:|---:|---:|---:|
| 完整前瞻（planner） | 95.67% | 91.45% | 84.85% | 72.91% | 64.86% |
| 會讀預告（forecast-aware） | 91.89% | 73.22% | 64.65% | 35.87% | 28.19% |
| 犯一次機制錯（planner-one-mistake） | 91.56% | 62.11% | 40.85% | 34.20% | 25.85% |
| 只看當下（greedy） | 91.89% | 88.67% | 79.13% | 65.32% | 66.82% |

前三種逐關嚴格下降；貪心打法在第 5 關比第 4 關高 1.5 個百分點，是唯一例外，照實保留。亂打、單系連按、固定循環從第 1、2 關起勝率就接近 0。

- **量不到的部分**：模擬玩家沒有理解與感受，所以「玩家是否看懂失敗原因」與「主觀上是否覺得後關更難」不在驗收範圍內，也沒有被量到。所有勝率都是機器策略的結果，不是玩家體驗數據。

### 已知限制

- **真實瀏覽器／手機實機檢查**（320／390／桌機）與 **14 個原創音效 cue** 未完成；目前有本機桌機與響應式畫面檢查。
- **兩個已揭露、門檻未放寬的缺口**：第 1 關失誤恢復率 91.23%（目標 75–90%）；第 5 關第三幕到達率 16.5%、重整中止機制很少被觸發。
- **P09 正式部署驗收未完整**：站台已上線並通過基本檢查，但資料來源抓取與 cron、快照暖機、備份還原與回退演練沒有留下紀錄。

### 文件導覽

- [開發進度](docs/DEVELOPMENT-STATUS.md)、[AI 派工與交接](docs/AI-HANDOFF.md)：實際狀態、驗收證據與下一步。
- [試玩後改版計畫](docs/P04-REVISION-PLAN.md)（三關主線＋兩關進階）、[分階段開發計畫](docs/DEVELOPMENT-PLAN.md)。
- [P10 難度曲線計畫](docs/P10-DIFFICULTY-PROGRESSION-PLAN.md)與報告：[P10-0 基線](docs/phase-reports/P10-0.md)、[P10-1 三幕引擎與 solver](docs/phase-reports/P10-1.md)、[P10-2 第 1、2 關](docs/phase-reports/P10-2.md)（[2.1 失誤量測](docs/phase-reports/P10-2.1.md)）、[P10-3 第 3 關](docs/phase-reports/P10-3.md)、[P10-4 第 4 關](docs/phase-reports/P10-4.md)、[P10-5 第 5 關](docs/phase-reports/P10-5.md)、[P10-6 三幕 UI](docs/phase-reports/P10-6.md)、[P10-7 全曲線驗收與部署清單](docs/phase-reports/P10-7.md)。
- [P08 易用性計畫](docs/P08-USABILITY-REVISION-PLAN.md)與 [QA 紀錄](docs/QA-REPORT.md)、[P08 報告](docs/phase-reports/P08.md)。
- [技術規格](docs/TECHNICAL-SPEC.md)、[資料契約](docs/DATA-CONTRACT.md)、[平衡鎖版](docs/BALANCE.md)、[遊戲設計](docs/GAME-DESIGN.md)。
- [美術與音效規格](docs/ART-AUDIO-SPEC.md)、[素材來源](docs/ASSET-SOURCES.md)、[P06 報告](docs/phase-reports/P06.md)、[P07 報告](docs/phase-reports/P07.md)、[Boost 安裝紀錄](docs/BOOST-SETUP.md)。
- 早期階段報告：[P00](docs/phase-reports/P00.md)、[P01](docs/phase-reports/P01.md)、[P02](docs/phase-reports/P02.md)、[P03](docs/phase-reports/P03.md)、[P04](docs/phase-reports/P04.md)、[P05](docs/phase-reports/P05.md)。

### 開發與驗證指令

```sh
php artisan game:simulate --seeds=20            # 策略矩陣
php artisan game:difficulty-report --suite=quick # 難度指數與失誤恢復率
php artisan game:solve                           # 離線證明關卡可解
php artisan game:playtest                        # 模擬新手世代代替真人試玩
php artisan opendata:refresh                     # 更新中央部會資料快照
```

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

- 規則只有一份，位於 `app/Domain/Game/`；`rules_version` 現為 `7.0.0`（P10-5：第 5 關三幕化與重整節奏；P10-0～P10-4 依序為其餘各關三幕化），數值與公式以[平衡鎖版](docs/BALANCE.md)為準。1.0.0、2.0.0、3.0.0、3.1.0、4.0.0、5.0.0、5.0.1 與 6.0.0 的舊局只能讀取與重播。
- **完全沒有亂數**：城市行為由關卡預告表決定，相同輸入必得相同事件與結局，重播不需保存亂數狀態。
- 六個 `/api/v1` JSON 介面掛在 `web` middleware group，仍受工作階段與 CSRF 保護；匿名存檔綁 HttpOnly session。
- 同一個 `action_id` 重送回放原結果不重新結算；版本過期回 409，規則不合法回 422 且不消耗回合或惡意。
- 每則事件含 `before`／`delta`／`after`／`reason_code`／`cue_id`，足以解釋傷害、護盾、印記、抗性、修復與勝負；前端只播事件，不重算。
- `php artisan game:simulate` 跑（關卡 × 情境 × 策略 × seed）矩陣。實測：規劃與貪心策略全數通關，合法隨機 0～15%，單系連按與舊版「三系集印記再放終招」套路全部 0%。
- P03 只實作舊版三個代表關卡驗證規則；新版五關在 P04 規劃、P05 落實，舊代表情境不等於新版完成關卡。機器策略勝率不是真人體驗證據；最終難度曲線見 P10 發布矩陣與模擬試玩。

## Phase 4 第 1 關切片與試玩後改版

- 第 1 關改以「第一禁術・枯潮｜讓城市喊渴」呈現，由枯潮教授・晏沉傳授禁術；保留 `empty-cup` 內部 ID 以相容既有存檔。
- 開局簡報、戰鬥提示、冷卻／破綻／城市回應與戰報都使用伺服器事件；戰報會列出本局實際打斷、修復、最大命中與蓄勢回合。
- 新增晏沉肖像與毀滅成功主視覺，保留素材提示詞、雜湊與來源紀錄；GitHub Pages 的 `docs/` 四個靜態入口未修改。
- 舊切片已有自動測試與桌機／手機畫面證據。2026-09-09～10 真人試玩提出在地招式、緊張感、卡牌主畫面、關卡數與回合斷點問題；目前採每回合五張手牌、進場及演出後自動發牌、30 秒連續決策，以及 hover／focus 預覽與單擊施放。新版程式已完成桌機與 390×844 響應式瀏覽器檢查；後續驗收改以模擬試玩取代逐人真人試玩，手機實機未做。

## Phase 5 五關戰役

- 五關內容全部交付：第 2 關護盾在系別間輪替、第 3 關需求脈衝、第 4 關護盾鏡射玩家上一次進攻的系別、第 5 關核心到門檻啟動兩回合重整。每一關都是在通過 100 seed 模擬門檻後才打開 `available`。
- 3.0.0 改了三條城市規則：護盾只吸收同系攻擊（終招沒有系別，任何盾都吃得到）、第 4 關的鏡射護盾、關卡可再設情境修正上限。改動理由與前後值見[平衡鎖版](docs/BALANCE.md) §4.6～§4.9。
- 3.1.0 依新試玩回饋限制同一張實體牌連續留牌；每回合最多留 2 張、免費換 1 張維持原規則。五關 100 seed 策略矩陣仍過既有門檻，見 [P08 報告](docs/phase-reports/P08.md)；難度曲線最終由 P10 收斂。
- 第 2、4 關通關後各挑一次牌組獎勵：兩張新牌二選一，**替換**掉一張既有試探，牌組維持 15 張。新牌重用既有技能，只換卡面——獎勵是策略分支，不是永久增傷。牌組在開局時凍結，之後挑的牌只影響下一局。
- 第 3 關通關即完成主線目標，可以收下戰果結束計畫，也可以接受進階畢業考；收手不是失敗，之後仍可挑戰進階，進階失敗也不撤銷主線通關。第 5 關通關取得進階終幕。
- P05 當時沿用第 1 關的圖；P06 已補齊 23 張原創插畫（見上方進度）。五關試玩驗證見上方「試玩驗證」一節。

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
