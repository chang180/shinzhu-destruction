# 開發進度與驗收紀錄

更新：2026-09-17。狀態只能使用 `未開始`、`進行中`、`待驗收`、`完成`、`受阻`。

目前已完成 P00 本機基線、P01 Laravel + Vue + SQLite + Boost 基礎、P02 三個部會資料 adapter 與快照備援、P03 伺服器權威戰鬥引擎與平衡鎖版、P04 首關限時手牌改版，以及 P05 五關戰役（皆待驗收）。現行範圍是三關主線＋兩關進階，**五關內容都已交付**，規則鎖在 `rules_version 3.0.0`；詳見 [P04 改版計畫](P04-REVISION-PLAN.md) 與 [P05 報告](phase-reports/P05.md)。全部勝率仍來自機器策略，3.0.0 的真人試玩尚未進行。

| 階段 | 狀態 | 驗收依據／下一步 |
|---|---|---|
| 文件基線 | 完成 | 七份文件與 README 入口；後續以使用者補充持續修訂 |
| 初批開放素材候選下載 | 完成 | 4 圖＋4 音效、兩份授權、manifest；圖片已目視，音效待試聽，尚未整合，不代表 P04／P06 完成 |
| P00 版本與環境 | 完成 | [P00 報告](phase-reports/P00.md)：本機版本、PHP 擴充、遷移邊界與 Pages 保護已驗證；Hostinger 實機能力保留至 P09 |
| P01 框架與 Boost | 完成 | [P01 報告](phase-reports/P01.md)、[Boost 紀錄](BOOST-SETUP.md)：Laravel/Vue/SQLite、lockfile、測試、建置與 Boost 全選驗證通過 |
| P02 資料轉接 | 完成 | [P02 報告](phase-reports/P02.md)、[資料契約](DATA-CONTRACT.md)：三來源本機實連成功，逾時／403／429／HTML 假成功／缺欄位／過期／零值缺值均有測試；正式主機解析樣本待 P09 前補齊 |
| P03 戰鬥引擎 | 待驗收 | [P03 報告](phase-reports/P03.md)、[平衡鎖版](BALANCE.md)：確定性引擎、事件契約、六個 `/api/v1` 介面、去重與版本語意、三關策略矩陣；真人試玩與效能壓測留給 P08 |
| P04 首關限時手牌改版 | 待驗收 | [P04 報告](phase-reports/P04.md)：五張手牌、留牌／換牌、進場與演出後自動發牌、連續 30 秒窗口、hover／focus 預覽與單擊施放已實作，`rules_version` 維持 `2.0.0`；145 個後端測試、桌機及 390×844 響應式瀏覽器檢查通過。**完整真人試玩、操作錄影與手機實機仍待補** |
| P05 五關戰役 | 待驗收 | [P05 報告](phase-reports/P05.md)：第 2～5 關的機制、數值與牌組已依 100 seed 模擬訂定並打開 `available`；同系護盾、鏡射護盾、逐關修正上限升版至 `3.0.0`；牌組獎勵（第 2、4 關後）、主線收手結局與進階終幕已交付。164 個後端測試、39 個前端純邏輯測試、桌機瀏覽器檢查通過。**3.0.0 的真人試玩、獎勵牌組的模擬與手機檢查仍待補** |
| P06 正式視聽 | 待驗收 | [P06 報告](phase-reports/P06.md)：23 張原創 PNG 已生成、轉成 23 個 WebP（約 9.1 MiB）、寫入 manifest 並依關卡／事件接入前端。手機實機、瀏覽器實機、音效試聽與真人試玩尚未完成，不能視為完整 P06 驗收。 |
| P07 結局複盤 | 未開始 | 10 份關卡勝敗樣本、主線與進階兩種終幕 |
| P08 全面驗收 | 未開始 | 策略矩陣、真人試玩與主機前驗證 |
| P09 正式部署 | 未開始 | Hostinger 實測、備份還原及 README 切換 |

## 最新實作基線

- `rules_version` 鎖在 `3.0.0`（`config/game.php`）；數值與公式以 [BALANCE.md](BALANCE.md) 為準，改動必須升版否則舊局重播會對不上。1.0.0 與 2.0.0 的舊局只能讀取與重播。
- 3.0.0 改了三條城市規則：**護盾只吸收同系攻擊**（終招沒有系別，任何盾都吃得到）、第 4 關的護盾**鏡射玩家上一次進攻的系別**（回合表寫死的預告優先）、關卡可以再設 `modifier_cap` 夾一層情境修正上限（第 5 關 `0.08`）。
- 五關 `available` 全部為 true。每一關都是在通過 `tests/Feature/Game/StrategyMatrixTest.php` 的門檻後才打開：planner／greedy ≥ 70%、planner − random ≥ 25 點、legacy-cycle < 80%（第 1 關 < 95%）、單系連按 < 80%、非法選擇 0。
- 牌組獎勵在第 2、4 關通關後各一次，兩選一**替換**既有試探，牌組維持 15 張；新牌全部重用既有技能，只換卡面。獎勵寫在 `campaigns.deck_choices`，牌組組成在開局時凍結進 `runs.deck`，所以重試與重播抽到同一副牌。練習通關不給獎勵也不給里程碑。
- 戰役里程碑寫在 `campaigns.milestones`：第 3 關通關記 `main_cleared`，收手記 `stood_down`，第 5 關通關記 `advanced_cleared`。收手是結局不是失敗，之後仍可挑戰進階；進階失敗不撤銷主線通關。
- 戰鬥引擎在 `app/Domain/Game/`。城市行為仍完全由關卡預告表與已保存的局面決定，**沒有亂數**——包含第 4 關的鏡射護盾，它讀的是局面裡的 `lastAttackElement`。唯一的亂數是牌序，而牌序完全由 `(seed, 第幾次洗牌)` 決定並保存在局面裡，所以相同輸入仍必得相同事件與結局。
- 一個回合分成 `reveal`（揭牌、開 30 秒窗口）與 `play`／`timeout`（結束回合）；`swap` 在窗口內可用一次。前端進入戰鬥與演出完成後自動送出 `reveal`，不再顯示逐回合開始按鈕。時鐘只有一個，在 `RunService`——引擎沒有時間概念，所以重播不受重播當下的時間影響。
- 到達截止時間（含相等）才抵達的出牌或換牌，改判為同一筆逾時結算並保留原 `action_id`，整個回合只結算一次。唯讀查詢不收斂逾時。
- `runs.mode` 分 `challenge`／`practice`；練習只關掉截止時間，解鎖與最佳表現寫在 `campaigns` 的 `practice_unlocked`／`practice_results`，不冒充限時通關。
- 局面同時保存抽牌堆順序，所以 `BattleState::toArray()` 是**完整存檔，不能直接送到前端**；對外一律 `toPublicArray()`（手牌、棄牌、剩餘張數）。
- 五關固定在 `config/game.php`。`available` 是「內容做完了沒有」，和玩家解鎖無關；P05 之後五關都是 true，未解鎖的關卡回 403 `level_locked`，內容未交付的關卡才回 409 `level_unavailable`。
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
| 本次指派階段 | P06 正式視聽（美術派工階段） |
| 執行者及日期 | Claude Sonnet 5，2026-09-17 |
| 基準／交付 commit 或未提交變更 | 基準 `02649d6`；本次為未提交變更（新增 `docs/P06-ART-GENERATION-BRIEF.md`，更新本檔與 `docs/AI-HANDOFF.md`） |
| 階段報告相對路徑 | 尚未建立 `docs/phase-reports/P06.md`（美術與程式整合都還沒做，不預先建立假完成報告） |
| 已通過項目 | 已確認 P00～P05 現況、盤點 `config/game.php` 缺口、寫出 23 張圖的生成派工單並附提示詞、尺寸、輸出格式與交回規格 |
| 尚未驗證／受阻原因 | 本 session 沒有圖片生成工具，美術產出已外派給使用者另找的 AI 執行，尚未回收；回收前無法做轉檔、manifest 整合、程式接線或任何視覺驗收。P07～P09 的真人試玩、手機實機、Hostinger 部署仍需使用者親自執行或提供帳號 |
| 下一位執行者第一步 | 先問使用者美術產出是否已回收：若有，依 `docs/P06-ART-GENERATION-BRIEF.md` §5 交回格式核對 23 張圖，做 WebP 轉檔、寫入新的 `assets/p06-generation.json`、接上 `config/game.php` 對應的關卡／卡牌／終幕元件，並跑桌機與手機 viewport 檢查；若還沒回收，繼續處理不依賴美術的自動化項目（例如 P07 的關卡勝敗戰報樣本、P08 的矩陣與壓測腳本），把純人工項目留在報告待辦清單 |


## 決策變更紀錄

| 日期 | 決策 | 理由與影響 |
|---|---|---|
| 2026-09-17 | P06 原創插畫改由使用者外派給其他 AI 生成，本 repo 內的 Claude Code session 只負責寫派工單與後續整合 | 本 session 沒有圖片生成工具；依 `docs/AI-HANDOFF.md` 與 `.ai/rules` 的既有規則，沒有生成工具的執行者可以做其他工作但素材交付保持未完成，不能用占位圖或宣稱完成代替。派工單見 `docs/P06-ART-GENERATION-BRIEF.md` |
| 2026-09-17 | P07～P09 的真人試玩、手機實機與 Hostinger 部署列為人工待辦，其餘可自動化項目由 AI 繼續往下做 | 這些項目需要實體裝置、真人操作或主機帳號，AI 在純終端環境無法完成；使用者要求先做完所有能自動化的部分，人工項目集中列在各階段報告的待辦清單，避免和「已完成」項目混在一起 |
| 2026-09-17 | 護盾改為只吸收同系攻擊，並升版至 `3.0.0` | 2.0.0 的護盾吸收任何系別，等於一筆全系傷害稅，第 2 關「換系繞盾」在程式上並不存在。終招沒有系別，任何盾都會吃掉一部分，避免它變成無視所有防禦的萬用解答 |
| 2026-09-17 | 第 5 關加上逐關 `modifier_cap = 0.08` | 三系同時採用資料時，`low`／`high` 的差距會直接決定勝負，而玩家無法選擇開局資料。這也是 P04 計畫寫的「三系綜合，仍有修正上限」 |
| 2026-09-17 | 第 2 關 8 → 9 回合；第 2、5 關加上可打斷的修復 | 只有護盾時，舊版固定循環在 `high` 情境仍有 90% 勝率；修復要用擾序打斷，正好是那條循環不做的事。多一回合讓留牌與等窗口成為可行選擇 |
| 2026-09-17 | 牌組獎勵的四張新牌全部重用既有技能 | 新技能等於新的平衡維度。獎勵要是策略分支（多一張破陣還是多一張擾序），不是永久增傷 |
| 2026-09-10 | 戰鬥改為自動接續發牌，卡牌單擊直接施放 | 驗收認為逐回合開始與二次確認削弱節奏；hover／focus 保留詳細提示，下一手倒數等演出完成才開始，避免動畫侵占決策時間 |
| 2026-09-09 | P04 重新進行限時手牌改版，規劃 3 關主線＋2 關進階 | 真人試玩指出地方連結、緊張感與選擇不足；使用者選定 30 秒／練習與真正手牌，要求本階段先規劃全程與提前結束。日常環境壞行為轉成有不同用途的卡牌 |
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
