# 技術架構、資料與部署規格

更新：2026-09-08。P00／P01 的版本與基礎、P02 的資料轉接已實作；戰鬥、視聽與部署仍是待實作契約。實際完成狀態以[進度表](DEVELOPMENT-STATUS.md)及階段報告為準。

**第 4 節的資料規格已由 P02 落實並細化。欄位、單位與品質門檻以[資料契約](DATA-CONTRACT.md)為準；本節保留為選型理由與邊界說明。**

## 1. 版本基線與更新方式

| 項目 | 本次查閱結果 | 執行要求／官方來源 |
|---|---|---|
| Laravel | 已安裝 `13.30.1` | [官方 release](https://github.com/laravel/framework/releases/tag/v13.30.1)；版本鎖在 `composer.lock` |
| Vue | 已安裝 `3.5.42` | [官方套件 metadata](https://registry.npmjs.org/vue/latest)、[發布政策](https://vuejs.org/about/releases)；版本鎖在 `package-lock.json` |
| Laravel Boost | 已安裝 `2.7.1` | [官方 release](https://github.com/laravel/boost/releases/tag/v2.7.1)；版本及選項矩陣見 [Boost 紀錄](BOOST-SETUP.md) |
| PHP | Laravel 13 至少 PHP 8.3 | [Laravel 13 發布說明](https://laravel.com/docs/13.x/releases)；主機 Web／CLI 皆須符合最終 lockfile 要求 |
| Node／Vite／TypeScript | 依當時穩定版本的 engines／peerDependencies | Node 只供本機或 CI 建置；前端相關套件一併核對相容性，不硬搬舊版 lockfile |

上述版本已於 2026-09-08 在本機安裝並由 P01 測試；後續 `composer install`／`npm ci` 應重現鎖定版本，不在每次部署執行無約束 update。需要升版時更新鎖檔、Boost 指引及受影響測試。

## 2. 應用架構與遷移邊界

```text
瀏覽器 Vue + TypeScript
    │ 同源 HTTPS，匿名工作階段及 CSRF
    ▼
Laravel 頁面殼／JSON controllers
    ├─ BattleEngine：唯一規則計算來源
    ├─ ReplayService：事件重播與結局複盤
    ├─ SQLite：匿名進度、局面、事件、精簡資料快照
    └─ file cache／file session

Hostinger cron → Laravel 資料更新命令 → 中央部會資料資源
                                               ↓
                                  驗證、清洗、發布新快照

圖片／音效 → 開發時生成或下載、處理 → public 靜態資產
```

正式目錄預期為 Laravel 慣例：`app/`、`bootstrap/`、`config/`、`database/`、`resources/js/`、`routes/`、`storage/`、`public/`、`tests/`。Vue 在 Blade 頁面殼掛載；controllers 保持薄層，遊戲規則置於 `app/Domain/Game/`，資料 adapter 置於 `app/Services/OpenData/`。

根 `package.json` 改為前端工具設定；把探測器 npm scripts 與啟動入口移至 `api-probe/` 的獨立設定，必要時調整 `server.js` 引用。`api-probe` 的歷史報告不刪除。舊 `docs/game.js` 可作遷移對照及歷史展示；正式版不得從它 import 遊戲規則。切換前維持 Pages 可玩，切換後清楚標為歷史版本。

首版只做單一同源服務，不引進登入套件、支付、即時多人、SSR 或遊戲執行時 LLM。開發時使用生成圖片工具與遊戲執行時需要生成服務，是兩件不同的事。

## 3. Laravel Boost：非互動全選及使用契約

### 3.1 安裝不能止於 composer require

完成 Laravel 骨架後，以當日最新穩定依賴安裝：

```sh
composer require laravel/boost --dev --no-interaction
php artisan boost:install --help
```

先把當前可選項寫入設定，再執行：

```sh
php artisan boost:install --guidelines --skills --mcp --no-interaction
```

**這行指令本身不代表全選完成。** 已查閱的 [v2.7.1 安裝器](https://github.com/laravel/boost/blob/v2.7.1/src/Console/InstallCommand.php) 在非互動模式會沿用 agent、第三方套件與整合的已存預設；沒有通用 `--all` 或 `--agents=all` 參數。其失敗處理也可能輸出錯誤後仍回傳成功碼，所以不能只看 exit code。

### 3.2 全選範圍與可重跑設定腳本

P01 已建立專案內的 `scripts/configure-boost.php`；下列要求已落實，後續升版須重新核對：

1. 啟動 Laravel console kernel；核對實際 vendor 版本、`boost:install --help` 及安裝器原始碼，再決定參數與設定格式。版本不同先調整腳本，不直接改 vendor。
2. 列舉安裝器所有已註冊且具 guidelines／skills／MCP 任一能力的 AI agents，寫入全部選項，不只本機偵測到的一個 AI。
3. 列舉專案已安裝且提供 Boost guidelines／skills 的第三方 packages，全部加入；這不代表從套件庫安裝所有第三方 PHP 套件。
4. guidelines、skills、MCP 全開；列舉本版整合選單的可用項目並全選。v2.7.1 包括 skills 啟用時可選的 Laravel Cloud，以及安裝器偵測可用時的 Nightwatch、Sail。不可因部署在 Hostinger 就漏掉可安裝的 Cloud skill；它不代表改用 Cloud 部署。
5. v2.7.1 可用 `Laravel\Boost\Install\AgentsDetector::getAgents()`、`ThirdPartyPackage::discover()` 取得清單，以 `Laravel\Boost\Support\Config` 的 setters 寫入 `boost.json`；參照[設定實作](https://github.com/laravel/boost/blob/v2.7.1/src/Support/Config.php)。這些是**版本相關內部介面**，腳本必須有版本／方法存在性檢查。
6. 不刪掉其他 MCP servers 或使用者自訂規則；合併前保存差異，產物只寫專案範圍。沒有專案設定位置的 agent 另列平台限制，不修改其他專案或全機帳號設定來假裝完成。
7. 每項標記 `已選且成功`、`本版／本環境不可選` 或 `失敗待處理`。不可選要附偵測依據；網路下載失敗、設定寫入失敗不能算不可選。未安裝 agent 客戶端可以生成設定，但其連線只能記「未實測」。
8. 若 Sail 整合使 MCP 指向容器，須驗證當前開發環境能執行；同時保留可用的原生 PHP MCP 開發設定，且在交接文件明列選用方式。選取整合不應使一般 Laravel 本機啟動或 Hostinger 執行依賴 Docker。
9. 安裝器之外的設定選項不等同安裝選單；不得為了「全選」加入未要求的付費帳號、監控服務或生產依賴。所有本版實際可選項都要出現在報告矩陣。

### 3.3 必須留下的安裝及使用證據

- `composer show laravel/boost`、鎖檔版本、實際命令、完整去秘密化輸出。
- 全部可選功能／agent／第三方指引／整合的前後對照矩陣，以及 `boost.json`。
- 檢查生成的指引、skills、MCP 設定真的存在且內容非空；不得有「未選 agent」、下載失敗或渲染失敗被忽略。
- `php artisan list` 中有 Boost 指令；以當前可用 AI 客戶端實際呼叫專案資訊與文件搜尋等唯讀工具，保存成功結果摘要。MCP 設定檔存在不等於客戶端已連線。
- 讀取生成指引，使用版本相符的文件搜尋、Laravel 慣例、必要測試與格式檢查；每階段報告簡述用到的指引／工具。只有已確認的工具才能標示已使用。
- 自訂規則放 `.ai/guidelines/`；按生成指引完成適用的 conventions 推導／記錄流程。若環境沒開放某項工具，明列限制與文件查證替代方法，不能冒稱有呼叫。
- 重跑設定／安裝後必要選項不消失；更新套件後依新版介面重跑並核對 diff。

正式發布使用 `composer install --no-dev`，且明確 `BOOST_ENABLED=false`；不公開開發用 MCP、Tinker 或日誌端點。

## 4. 資料來源與真實資料界線

先讀[根 README](../README.md)、[Hostinger 連線紀錄](../api-probe/HOSTINGER-RESULT.md)與[可行性盤點](../api-probe/DATA-SOURCES-FEASIBILITY.md)。採用以下資料集，不重新從縣府失敗主機開始選型：

| 資料集 | 正式來源方向 | 遊戲用途 | 清洗與限制 |
|---|---|---|---|
| [45501 水庫水情](https://data.gov.tw/dataset/45501) | 水利署 `opendata.wra.gov.tw` 資源 | 水系情境修正、補給預告強度 | 寶山第二 `10405`、永和山 `10501`；代碼對照需保存來源，名稱及所在地／供水關聯分開；不能宣稱兩座都在新竹縣 |
| [41280 園區用水量](https://data.gov.tw/dataset/41280) | 國科會 `mas.nstc.gov.tw` 資源 | 區域需求節奏、補給消耗情境 | 區別園區、月份、單位；不是全新竹縣即時用水，竹南也不能當新竹行政區 |
| [178038 國土利用統計](https://data.gov.tw/dataset/178038) | 內政部 `opdadm.moi.gov.tw` 資源 | 熱／土地情境、綠地與建成地配置 | 以行政區代碼／名稱篩選新竹縣 13 鄉鎮市；核對調查年度、分類與面積單位；不是即時熱島測量 |

`data.gov.tw` 是來源目錄；P02 從資料集頁解析／人工核實當時正式資源 URL，記錄在版本化 adapter 設定，不能把頁面 HTML 當資料 API。上游可能是批次 JSON／CSV，不能自創縣市篩選參數；依實際文件決定整批或有文件支持的分頁。

先前報告記載 `mas.nstc.gov.tw` 的 User-Agent 差異；P02 以明確、固定且可配置的 UA 實測，遵守資料來源使用方式，不關閉 TLS 驗證。403／429 應作受控失敗及備援，不無限重試。**P02 實測結果：2026-09-08 從本機以 `curl/8.8.0` 與專案 UA 呼叫 `mas.nstc.gov.tw` 皆為 HTTP 200，舊報告的 403 未重現；仍固定送出可識別 UA，並保留 403 的受控失敗處理。**氣象署先前 HTTP 401 只證明可連線，不是可用資料；首版不以它為必要來源。

水庫資料有觀測時間、有效蓄水量等欄位，但百分比是否可直接取得須依實際 payload；只有具同一水庫、同一口徑容量來源才可計算比例。沒有足夠欄位就使用有明列公式的情境指標或中性值，不能捏造蓄水率。**P02 實測確認 45501 資源沒有滿水位容量與蓄水百分比欄位，因此改用同一水庫自我比較的 `storage_index`，不輸出蓄水率。**官方資料頁亦提醒避免把蓄水率用水情燈號色彩表達；原始資料面板使用中性色與數值，與虛構戰鬥血條分開。[水利署資料說明](https://data.gov.tw/dataset/45501)

### 4.1 正規化快照契約

每筆至少保存：`snapshot_id`、`source_id`、`resource_url`、`fetched_at`、`observed_at` 或 `period`、`schema_version`、`content_hash`、`quality`、`scope`、`metrics`、`units`。時間保存 UTC／明確時區，UI 顯示 Asia/Taipei；年份須區分民國與西元。

- `quality`：`fresh`、`stale`、`demo`、`unavailable`。混合情境必須逐來源顯示品質，不能因其中一個新資料而整局標「即時」。
- `scope`：水庫／園區／鄉鎮市代碼及統計期間；不可把不同尺度加成虛構總量。
- `metrics`：僅存必要欄位；零是有效值，空白、`--`、解析錯誤轉缺值並附原因。
- 情境修正固定在每系 `-15%～+15%` 的初始遊戲範圍；公式、基準與截斷值在 P03 的 `BALANCE.md` 固定。不是物理災害模型。
- 園區需求初稿採同園區最新月份與最多 12 個有效月份中位數比較，至少 6 筆才使用；不足採中性。土地比例只加總互斥、可核對的分類，以同期間同口徑總面積為分母，無分母不得自行推算。

### 4.2 更新與備援策略

初始更新頻率：水庫每小時、園區用水每日檢查、土地利用每週檢查。這是**抓取頻率**，不是把月報／年度資料改稱每日／每週更新。

每來源獨立鎖；連線逾時初始 5 秒、單來源總工作預算 60 秒，最多 2 次有限重試且包含在預算內。大檔串流處理、限制實際 bytes；初始上限 50 MiB、以 P02 樣本及主機記憶體調整，不能無界下載。重新導向只許已核實來源主機。**P02 依實測樣本（283 KB／156 KB／818 B）把上限收緊為水庫 8 MiB、國土 16 MiB、園區 4 MiB；重試上限為 3 次嘗試（初次加 2 次重試），只對 429、5xx 與連線失敗重試。**

驗證通過才以短交易／原子切換發布新快照；網路下載與清洗不持有 SQLite 寫鎖。更新失敗保留最近有效資料。水庫觀測超過 48 小時先標 stale；月／年度資料依發布週期與調查期間判斷。**P02 已填明門檻：園區用水落後超過 1 個民國年、國土利用調查期間落後超過 2 個民國年才標 stale。**

無有效快照時使用附版本的 `demo` fixture，新開局提示「示範情境」；既有對局使用凍結快照繼續。UI 顯示來源、觀測期間及資料狀態，不顯示密鑰或完整內部錯誤。

## 5. SQLite 與存檔最小模型

初始設定：`DB_CONNECTION=sqlite`、`CACHE_STORE=file`、`SESSION_DRIVER=file`、`QUEUE_CONNECTION=sync`。開發可採預設 `database/database.sqlite`；正式 DB 使用 public 之外的持久絕對路徑，發布包不得覆蓋。

| 資料 | 最小內容 | 保存政策初稿 |
|---|---|---|
| `campaigns` | 匿名擁有者、解鎖進度、最佳成績、最後活動 | 30 日未活動可到期；UI 告知同瀏覽器存檔期限 |
| `runs` | 關卡、規則版本、情境快照組、種子、局面、版本、結局 | 最多保留每玩家最近 20 局詳細重播；較舊僅保留進度／成績摘要 |
| `run_actions` | action_id、request hash、順序、輸入、規則事件與差值 | 與局共存；不存每幀動畫，不含個人輸入文本 |
| `data_snapshots` | 標準化小型資料、品質、来源期間、hash | 保留仍被對局引用者及最近有效／備援版本，清理不可破壞重播 |

以不可猜測的匿名識別與 HttpOnly 工作階段綁定擁有者；知道 run ID 不等於能讀寫他人局面。無跨裝置帳號；cookie 遺失不承諾找回舊匿名進度。

開啟外鍵、驗證 busy timeout 與重試；WAL 只有在主機本地檔案系統及鎖實測通過才啟用，不假設共享空間一定適合。每回合短交易更新局面及追加事件，採條件版本更新避免雙分頁覆寫；不依賴 SQLite 不支援的 row lock 語意。

DB、事件及日誌容量目標：初始 1,000 位匿名玩家、每人最多 20 局、每局最多 18 回合下，量測後訂正式配額；首版 DB 軟預算 100 MiB、觸發清理／提醒，不能不分資料用途直接刪檔。日誌初始保留 7 天且遮蔽識別資料。SQLite 線上備份用一致性 backup 方法，或維護期間停止寫入再複製；WAL 啟用時不可只複製主 DB 檔假裝備份完成。

## 6. 同源 HTTP 與事件契約

以下為待實作 v1 介面，採 web session／CSRF 保護，即使 URL 使用 `/api/v1` 也不可漏掉 middleware：

| 介面 | 用途 |
|---|---|
| `GET /api/v1/levels` | 13 關、解鎖、可用情境摘要 |
| `POST /api/v1/runs` | 建立局、綁定快照與規則、回傳起始狀態 |
| `GET /api/v1/runs/{run}` | 擁有者續玩、目前版本、已結算事件 |
| `POST /api/v1/runs/{run}/actions` | 提交 `action_id`、`expected_version`、`skill_id`、合法目標 |
| `GET /api/v1/runs/{run}/replay` | 已結束對局的證據與複盤資料 |
| `GET /api/v1/data-status` | 去秘密化來源品質及觀測時間 |

一個成功行動回傳 `run_id`、`action_id`、`version`、`state`、有序 `events`、`outcome`。事件至少含 `event_id`、`turn`、`type`、`actor`、`target`、`reason_code`、`before`、`delta`、`after`、`cue_id`，足以解釋傷害、護盾、印記、抗性、修復及勝負。

- 同一 `(run_id, action_id)` 同 payload 重送：回傳原先結果；不同 payload：409。即使原局已結束，合法重送仍回原結果。
- `expected_version` 過期：409，取得現況再決策；驗證失敗：422，不消耗回合；限流：429，給可重試時間。
- 網路逾時後前端先查版本／重送原 action_id，不能新建一次施招；按跳過只結束演出，不再送行動。
- 引擎在同一交易內確認擁有者、有效狀態、資源、版本與 action 去重，結算後原子寫入。Vue 只展示及做輸入便利性檢查。
- 素材缺檔使用清楚的靜態戰果替代，不卡住結算；必須記入 QA 待修，不能以 fallback 永久取代正式素材。

## 7. Hostinger 發布與運維要求

依 [Laravel 官方部署要求](https://laravel.com/docs/13.x/deployment)及 [Hostinger 主機能力說明](https://www.hostinger.com/support/which-server-capabilities-are-supported-at-hostinger/)設計，**帳號實際能力仍須實測**。舊 Node 實驗的部署教學不能當 PHP 正式流程。

1. P00／P09 比對 Web 與 CLI PHP、Laravel 必要擴充，另驗證 `pdo_sqlite`、`sqlite3`、HTTPS client、檔案權限與鎖。若 CLI 路徑不同，cron 使用驗證過的絕對 PHP 路徑。
2. 本機／CI 執行前端正式 build；正式包含 `public/build` 與必要資產。不在共享主機長期執行 Vite、`artisan serve` 或 Node server。
3. 可調整 document root 時，指向 Laravel `public`；若固定 `public_html`，只放 public 內容，核心專案放其外，依**當前 Laravel 入口結構**修改 bootstrap／autoload／maintenance 路徑並測試。不能只靠隱藏網址保護整個專案根。
4. `.env`、固定 APP_KEY、SQLite、`storage` 與其他持久資料獨立於發布版本。正式 `APP_DEBUG=false`、HTTPS、secure cookies；敏感路徑 HTTP 檢查不可下載。
5. 使用鎖檔安裝正式 PHP 依賴；主機不能跑 Composer 時在相容 Linux／PHP 環境產生 vendor，驗證 platform requirements 再上傳，不能忽略平台檢查。依實際 scripts 確認 `--no-dev` 不會呼叫缺少的 Boost。
6. 備份 DB／持久設定 → 維護或相容切換 → 部署 → migrations → 設定／路由／view cache → 暖機快照 → 冒煙測試。不得使用 `migrate:fresh`／重建 APP_KEY 清掉正式資料。
7. Hostinger cron 呼叫 `artisan schedule:run`，頻率依方案實際能力；若只能較低頻率，調整更新時程與對外資料時效。排程使用鎖與執行預算；不要求常駐 scheduler／queue worker。可用性不足時記錄替代更新方式與陳舊標示，不能把自動更新標成完成。
8. 回退要同時考慮前一版程式、schema、規則版本與對局重播；不支援舊 active run 時保留進度並明示需重新開局，不能用新規則重算舊局並沿用舊戰報。
9. 驗證正式 URL、13 關配置、來源更新、兩種結局、續玩、SQLite 備份還原；保留一次排程成功與一次失敗備援證據。

## 8. 測試與效能門檻

- PHP 測試：資料 adapter、交易去重、版本衝突、擁有者隔離、規則、重播、複盤，採固定 fixture，不把真實上游當 CI 唯一依賴。
- Vue 測試：事件演出狀態、跳過／靜音／重新整理、回應重送；瀏覽器端到端測試走第 1、中期、13 關與兩種結局。
- 使用 Boost 指引要求的格式與測試工具；遊戲功能必須有有意義驗證，純文件修改只做內容／連結／差異檢查。
- 本機／staging 先以 20 個同時活躍對局、每局每 3 秒一個行動測試；正式共享主機壓测依資源限制控制。已暖機且不含上游抓取時，初始 action API 目標 p95 < 800 ms，500／資料鎖錯誤 < 1%，重複結算 0。這是待驗收目標，不是現有成績或容量承諾。
- 手機 390×844、桌機 1440×900；核心操作無橫向溢出；鍵盤可完成一局，狀態不能只靠顏色／聲音辨識。
- 首屏含首關必要圖像的壓縮傳輸預算 ≤ 3 MiB；其餘逐關載入；LCP 初始目標 ≤ 2.5 秒（記錄裝置／網路條件），一般演出盡量達 30 fps。完整正式圖像／音效初始預算 ≤ 35 MiB，原始素材包不隨頁面或發布包全量載入。
