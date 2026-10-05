# AI 派工與階段交接規範

## 2026-10-05 P10-4 交接

使用者已確認 P10-3 驗收完成；本輪從乾淨 `a6cd2f8` 接續第 4 關，交付本機單一 `feat(game): split level 4 into three acts and measure mirror followups` 提交，未推送、未部署。規則 **6.0.0**：照影／借影／反制考核起點 1／4／7、9 回合、鏡射盾 28、cap 0.04；防線 40／38／38 與通關後獎勵不變。

full planner **72.91%**（最低格 **60.67%**）、forecast-aware **35.87%**、配對恢復 **42.19%**（4983／11811，覆蓋率 100%）、DI **0.5365**，L3→L4 **+0.1398**；quick 同樣全過，三種弱策略全格 0%，278 後端測試、solver 120／120 可解且重播，TypeScript／50 probe／build／Pint／diff 皆通過。兩牌組 full planner 71.67／74.15%、forecast 38.52／33.22%、恢復 38.45／45.80%，合併加權過，煙囪恢復率分支略高已揭露。

Simulation/report 新增真實誘盾與下一手異系核心命中／破陣診斷，明確分母、JSON/CSV，排除靜態盾、取消鏡射、同系、終招、逾時等誤算；不改引擎公式、策略、失誤語意或量尺。99.54% planner 勝局有觀察到誘盾換系破陣，仍有例外，不宣稱是強制勝利條件或真人理解證據。

第 1、2、3、5 關／共用技能／獎勵／戰役逐項相同，Vue、Pages、routes 未改；5.0.1 舊局可讀與已結束事件重播，續打／retry／已結束 counterfactual 拒絕版本衝突。Boost MCP search-docs／record-rule 回 Transport closed，直接更新共享規則的限制見報告。完整 full、quick、26 候選與測試證據見 [P10-4 報告](phase-reports/P10-4.md)。P10-4 **待驗收（自動條件具備）**，全量 solver、真人／手機／本輪瀏覽器留各工作包。本輪停止；下一包 P10-5 需另行指派。

## 2026-10-05 P10-3 補修交接（歷史；使用者已確認驗收完成）

基準 `7a056a3`；本輪單一修補提交 `fix(game): close P10-3 balance gap and complete resource metrics` 推送 main，未部署。規則升 **5.0.1**，只改第 3 關第 4 回合為可打斷水修復 14；原 5.0.0／70.30% 未達歷史保留於 [P10-3 報告](phase-reports/P10-3.md) §1～§7，新結果見 §8。

full 27 情境 × 兩種實際獎勵牌組 × 300 seeds：planner **84.85%**（最低格 **65%**）、forecast-aware **64.65%**、配對失誤恢復 **44.53%**（覆蓋率 **99.83%**）、difficulty_index **0.3967**、L2→L3 **+0.0886**，全部原定 gate 通過。quick 同樣通過；**P10-3 待驗收（本補修包自動驗收條件已具備）**。兩牌組 forecast-aware 為潮汐 68.59%、煙囪 60.70%，煙囪優勢反轉，絕對差距沒有縮小；目標是既定逐關合併加權。恢復率分母 13,722，原 planner 勝局 13,746，24 局沒有合資格失誤；不能寫覆蓋率 100%。

新增真實引擎事件／終局狀態診斷：終招使用率、首次／勝局首次終招回合、全部／勝敗終局惡意，以及脈衝使用／修復打斷率。JSON／CSV 保留計數、總和及分母，無終招回合為 null；DI 仍 p10-di-3，策略、失誤語意與 gate 不變。5.0.0 舊局可讀、事件可重播，不能續打、重試或反事實；未結束反事實優先回 run_in_progress。

後端 **266 passed**、代表 solver **120／120 可解且重播**；ThreeActLevelsTest、DifficultyReportTest、LegacyRunCompatibilityTest、CounterfactualTest、Pint、typecheck、50 項 probe 測試、build 與 diff 檢查皆實跑通過。README、BALANCE、計畫、進度、規則與階段報告已同步；Boost MCP search-docs／record-rule 回 Transport closed，依本包明示要求直接更新 .ai/rules/game.md，未改 vendor。其他四關、Vue、路由與 Pages 四檔未改。第 1 關既有缺口、P10-7 全量 solver、真人／手機／本輪瀏覽器與部署仍未做。**本包結束即停止，不開始 P10-4、不部署。**

## 2026-10-05 P10-3 原收尾交接（5.0.0 歷史）

實作 commit `b69d67d` 已交付第 3 關三幕（`ledger-prep`／`demand-pulse`／`peak-settlement`，回合門檻 1／4／7），11 回合、防線 37／37／35、modifier_cap 0.06，規則升 **5.0.0**。Codex 接手後補完 27 情境 × 兩種第 2 關獎勵 × 300 seeds 的完整矩陣、120 局 solver、兩牌組各一勝一敗事件樣本與第 3 關實際預告／幕切換回歸。根 README、BALANCE、進度與 P10 計畫已同步，完整資料見 [P10-3 報告](phase-reports/P10-3.md)。交付已推送 `main`，未部署。

**不能沿用「全部 gate 通過」的接手摘要：**quick 第 3 關通過，但 full forecast-aware **70.30% 高於 68% 上限**；planner 83.85%（最低格 68%）、配對恢復率 47.97%（覆蓋率 100%）、L2→L3 +0.0672 均通過。主線 full 指標 0.1854→0.3081→0.3753 單調；五關曲線尚未完成。這輪保留數值並明列未達，不再掃值、不放寬區間。第 1 關 full 恢復率 91.2% 的既有缺口仍保留。

共用逐格下限採 P10 §3.4 既定的 **55%**，取代 P05 三情境測試的 70%；同時影響 planner 與第二種可行策略檢查。已透過 Boost `record-rule` 記入 `.ai/rules/game.md`。下限不能取代加權目標與難度曲線，也不能把 full greedy 53.33% 誤報成 planner 下限失敗。量尺維持 `p10-di-3`，失誤語意未改。

後端 **260 passed**、三幕窄測試 4 passed、Pint、TypeScript、build、50 項前端／靜態原型測試均實跑通過。本輪沒有瀏覽器、真人、手機實機或 Hostinger 部署證據。solver 120／120 是代表樣本，全量仍留 P10-7。P10-3 狀態為待驗收；先處理或審查 full forecast-aware 缺口，後續 P10-4 需另外指派，不能把本報告當作正式發布核准。

## 2026-10-05 P10-2.1 失誤量測語意修正交接

`planner-one-mistake` 原本的「關鍵窗口裡第一個語義不同且分數嚴格較低的合法行動」不能代表犯錯：第 1、2 關實測有近七成注入的「失誤」只是比較弱的合法打法，第 2 關 seed 1 甚至把 `disrupt.water`（正確打斷可打斷水盾）標成失誤。現在只承認兩種核心機制錯誤——`missed_interrupt`（預告可打斷、手上有同系擾序、planner 最佳就是打斷，卻改成不處理）與 `walked_into_shield`（有同系護盾、planner 最佳繞開了它，卻改成撞上去）——**兩者都要求 planner 最佳打法本身就是正確處理**，否則另一條合理策略會被誤標。逐回合檢查，第一個能構成明確錯誤的回合注入，每局最多一次，找不到記 `no_eligible_mistake`。`INDEX_VERSION` 升 **`p10-di-3`**；公式權重不變，但 `p10-di-1`／`p10-di-2`／`p10-di-3` 的第三項語意不同，**不可互相比較**。報告移除 `window_not_reached_games` 與 `legacy_second_*`，新增 `missed_interrupt_mistakes`、`walked_into_shield_mistakes` 與逐局 `mistake_kind`／`intent_*`／`shielded_elements`。結果：第 2 關恢復率 89.2%→**65.4%** 落進目標 60–75%，第 1 關 94.3%→**91.2%** 仍超出上限 1.2 點（**門檻沒有放寬**），第 1→2 關 Δ difficulty_index 由 +0.071 擴大為 **+0.123**（full）。六個未改策略的加權勝率與逐格分布逐欄相同，`rules_version 4.0.0` 與第 1、2 關數值一個字沒改。外部驗收（Codex）後再修兩項量測缺陷：子類在「每一種出牌都會輸」的回合沒走 `LookaheadStrategy::choose()` 的蓄勢分支（取捨邏輯已抽成共用的 `pick()`）；以及 `walked_into_shield` 的候選有可能同時正確打斷當前預告（五關 1200 局命中 135 局，**第 3～5 關恢復率因此下修 0.3～5.0 點，第 1、2 關不變**）。報告新增 `eligible_mistake_coverage`：第 1 關只有 46.4%，其餘四關 98–100%，所以第 1 關的恢復率只能解讀為「在能犯錯的那半數局裡」。撞盾判準仍是 planner 條件化，而且撞盾不等於整招白丟（89% 傷害被吸收、62% 核心零傷害，但防線與印記仍生效）；要不要把判準改嚴是量測契約變更，留給計畫擁有者。下一包 P10-3，入口見 [P10-2.1 報告](phase-reports/P10-2.1.md)；**不要再改量測語意**。交付 commit `1e337d2`、修正 commit 見報告，皆已推送 `main`，未部署。

## 2026-10-05 P10-2 第 1、2 關三幕化交接

第 1 關改成試杯（第 1～2 回合只補強）／斷補（第 3 回合修復 19）／空杯小考（第 6 回合修復 20）；第 2 關改成單盾示範（第 2 回合**不可打斷**的熱盾）／輪班防線（第 3、4 回合水盾、土地盾）／交錯窗口（第 5、7 回合修復 22／26 與第 6、8 回合護盾交錯）。觸發只用 `turn_gte`，所以 `GET /api/v1/levels` 的 `forecast` 逐回合取所在幕的預告並帶 `level_phase_id`，和實際對局一致。`rules_version` 升為 **4.0.0**；3.1.0 舊局唯讀，反事實比較也改回 409 `rules_version_mismatch`（原本會用新規則替舊局算）。quick／full 矩陣兩關的 planner 與 forecast-aware 都在 P10 目標區間，第 2 關比第 1 關高 +0.109／+0.071；**失誤恢復率兩關都高於目標上限**，報告列了掃描結果與原因，沒有放寬門檻。難度報告新增幕次到達率欄位。第 3～5 關未動，五關曲線仍不單調。下一包 P10-3，入口見 [P10-2 報告](phase-reports/P10-2.md)；交付 commit `07a2c90` 已推送 `main`，未部署。

## 2026-10-05 P10-1 三幕引擎交接

P10-1 已完成三幕引擎前置，但**沒有任何關卡變成真正三幕**。關卡幕次存在 `BattleState::$levelPhaseId`（`state.level_phase_id`、事件 `level_phase_change`），和第 5 關重整的機制狀態 `state.phase`（standby／overhaul、事件 `phase_change`）分開；設定改為 `level_phases`（預告表在幕內）與 `mechanic_states`。觸發只有 `turn_gte`／`core_lte`／`flag_true`／`any_of`，載入時驗證。五關目前各一幕 `main`，等價證據見 `tests/Fixtures/p10-1/equivalence.json` 與 `LevelPhaseEquivalenceTest`。新增離線 solver `php artisan game:solve`，只證明存在解，不是玩家策略。P10-0／P10-0.1 報告裡「worktree 逐位元相同」的舊比對方法有誤（`vendor/` 符號連結），已用正確方法重做且結論成立。下一包 P10-2，入口見 [P10-1 報告](phase-reports/P10-1.md)；交付 commit `6f3db2b` 已推送 `main`，未部署。

## 2026-10-05 P10-0 難度量測交接

依 [P10 計畫](P10-DIFFICULTY-PROGRESSION-PLAN.md) 完成 P10-0：新增 `php artisan game:difficulty-report`（`--suite=quick|full`、`--seeds`、`--json`、`--csv`），量測五關 × 9／27 情境 × 合法獎勵牌組 × 7 策略，含新策略 `forecast-aware`、`planner-one-mistake` 與逐關 `difficulty_index`。規則、關卡、城市意圖與 `rules_version 3.1.0` 完全未改；`game:simulate` 輸出與改動前逐位元相同。3.1.0 基線證實曲線不單調（第 2→3、第 4→5 關 difficulty_index 下降），且一次失誤幾乎不影響勝率。`solver` 未實作，planner 不能當可解性證明。下一包是 P10-1（三幕引擎與設定驗證器），入口與數字見 [P10-0 報告](phase-reports/P10-0.md)；本包已提交 `caf5df9` 並推送 `main`，未部署。

P10-0.1 修正了 P10-0 的失誤量測：舊版「排名第二」約有兩成是同招另一張實體牌、三成以上與最佳同分，不能算失誤。現在失誤必須語義不同（`type|skill_id|target|fixed`）且 planner 分數嚴格較低；恢復率改為同 seed 配對（planner 原本通關且真的注入失誤的局為分母），`INDEX_VERSION` 升 `p10-di-2`。`game:difficulty-report` 會拒絕未知策略／關卡與 seeds < 1，並新增 `--mistakes-csv`。quick／full 基線已重產，曲線不單調的結論不變。P10-0.1 已提交 `8da941e` 並推送 `main`，未部署；P10-1 尚未開始。

## 2026-09-30 P08 試玩回饋交接

玩過的人指出字太小、看不懂卡牌用途及目標、通關後看不懂原因且結算畫面不足。已核對現行 Vue 畫面與戰報邏輯，建立 [P08 易用性改版計畫](P08-USABILITY-REVISION-PLAN.md)。這三項易用性修正已實作，實際結果見 [QA 紀錄](QA-REPORT.md)：必要字級 16px、輔助 14px，卡牌與固定行動提供不施放的用途入口，結算首屏以事件說明核心變化、最後一擊及最多三條原因。結束時重新讀取關卡列表，讓剛解鎖的下一關立即出現在戰報。桌機、390px、320px、模擬文字放大與本機第 1 關勝敗已檢查；接手先安排至少 5 位真人與手機實機回測，再補其餘 P08 驗收。卡牌目前自帶系別，攻擊城市核心並受對應防線影響，沒有手動選目標；若要改成選目標，另立規則需求。P06 音效缺項與 P08 其餘驗收項仍依既有文件追蹤。

使用者另指出留牌／捨牌似乎太自由，並選定「每回合仍換 1 張；同張牌不能連續留」方案。已將限制寫入伺服器規則及前端留牌按鈕，升 `rules_version 3.1.0`；最多留 2 張不變。前次保留的實體牌 ID 存在 `BattleState.keptLastTurn`，對外投影為 `kept_last_turn`。策略與測試同步調整，五關 100 seed 矩陣仍過既有門檻；真人難度回測待補。留牌與改版計畫已接上遠端部署規則更新，提交 `b9d3656` 並推送 `main`。本次 P08 介面交付包含改版、測試與 QA 紀錄。證據見 [P08 報告](phase-reports/P08.md)；3.0.0 舊局可讀與重播，不可繼續。

## 開始工作前

1. 讀根目錄 `README.md`、[開發計畫](DEVELOPMENT-PLAN.md)、[進度表](DEVELOPMENT-STATUS.md)、當階段相關規格及上一階段報告。
2. 檢查目前分支、未提交變更及適用的 `AGENTS.md`／AI 指引。不得清除別人變更、強制重設或覆蓋既有文件來取得乾淨目錄。
3. P01 之後，先讀 Boost 生成指引與 `.ai/guidelines/` 專案規則；使用可用的 Boost 文件搜尋及專案資訊工具查證套件用法。不能只說自己熟悉 Laravel。
4. 確認前置階段有驗收證據。若文件寫完成但程式不存在，先補正狀態並回報，不沿用失真的完成記錄。
5. 先列本階段工作與驗收項，執行到具體可驗收；不必為規格內的可逆實作反覆詢問。

## 必守專案約束

- 最新穩定 Laravel + Vue、SQLite、Hostinger PHP 共享空間；不得自行換成其他框架、MySQL、VPS 或依賴常駐服務。
- 現行範圍為 3 關主線＋2 關進階，取代原 13 關要求；13 鄉鎮市只是資料涵蓋範圍。遊戲主角是反派學員，毀滅成功是玩家勝利。
- 資料來源依 README 的中央部會盤點；不可把縣府主機重設成主要來源。
- 不把缺值當零，不把模型假設寫成實際環境測量，不在遊戲中途更換資料快照。
- 不把圖片需求降級成 emoji、純 CSS 或文字占位；不可宣稱生成完成但沒有檔案。
- 施招演出與結局依真實事件紀錄；客戶端動畫不可再算一次傷害或決定勝負。
- 首版不依賴即時 LLM；沒有生成工具的執行者可以完成其他工作，但素材交付保持未完成並交接實際缺項。
- 只完成本次被指派的階段；若必要修改跨階段共用介面，更新契約與影響清單。
- 未經實測不能寫「Hostinger 已可部署」「難度已平衡」「Boost 全選完成」。

## P07 已交付：反事實比較服務＋前端一鍵比較

2026-09-17 完成 P07：`app/Services/Game/CounterfactualComparator.php` 換掉一局已結束對局裡的某一次決策，重建那一手之前的局面，套用替代行動後交給 `BattleSimulator::continueFrom()`（`BattleSimulator` 新增的入口，可從任意局面接續模擬）用 `planner`／`greedy` 策略打完剩下的回合。新端點 `POST /api/v1/runs/{run}/counterfactual` 只讀不寫，擁有者隔離與未結束局面的檢查方式和其他端點一致。戰報頁的完整行動紀錄裡，每一筆出牌旁邊可以按「如果這回合改蓄勢，結果會怎樣？」立即看到比較結果。完整變更、10 份全五關勝敗樣本與限制見 [`phase-reports/P07.md`](phase-reports/P07.md)。

「兩套獨立結局」的文案／畫面／音效判定為 P04～P06 已經做完，P07 沒有重做，只在報告裡確認。

接手時：先問使用者 P06 音效是否已回收（見下一段）；音效之外，下一步是 `DEVELOPMENT-PLAN.md` §P08 全面驗收——真人試玩、手機實機與跨分頁／斷線等情境。反事實比較這個新功能本身也還沒有真人試玩過，也沒做手機 viewport 檢查，不要當成已經驗收。

## P06：美術已交付並已 push，音效還在等回收

2026-09-17 已依 [`P06-ART-GENERATION-BRIEF.md`](P06-ART-GENERATION-BRIEF.md) 生成、回收與整合 23 張原創插畫，並經另一個 Claude Code session 核對 SHA-256、抽查構圖與全套測試／建置後，commit `a0073b0` push 上 `origin/main`。原稿為 `assets/generated/p06/`，正式 WebP 為 `public/assets/p06/`；來源、提示詞、hash、尺寸與轉檔關係見 `assets/p06-generation.json`，完整變更與限制見 [`phase-reports/P06.md`](phase-reports/P06.md)。美術部分接手時不必再問是否回收，直接視為已整合。

**音效還沒有。** 現況只有 4 個 Kenney CC0 命中音（`public/assets/p04/*.ogg`）與程式即時合成的施法／結局純音，沒有原創錄製或生成的音效檔。已寫出可外派的 [`P06-AUDIO-GENERATION-BRIEF.md`](P06-AUDIO-GENERATION-BRIEF.md)（14 個 cue：4 個 UI 提示音、3 個系別施法音、1 個修復音、3 個終招音層、3 個結局樂句）。接手時先問使用者音效產出是否已回收：

- 沒回收：不要卡住，去做不依賴音效／美術的自動化項目，目前是 P07 行動複盤與兩種獨立結局（見 `DEVELOPMENT-PLAN.md` §P07）。
- 已回收：依派工單 §3 交回格式核對 14 個檔案，正規化並轉出瀏覽器相容格式，接上 `resources/js/audio.ts`，回頭補齊 `phase-reports/P06.md` 的音效驗收項，才能把 P06 狀態從「待驗收」推進到「完成」。

手機實機、跨瀏覽器實機與真人試玩仍是未完成項，不能把本次資產整合稱為完整視聽或真人驗收。

P07～P09 涉及真人試玩、手機實機操作與 Hostinger 正式主機帳號，這些是這個純終端 AI session 做不到的事；依使用者指示，這些項目要在對應階段報告中列成明確的人工待辦清單，而不是卡住其他可自動化工作的進度。

## 可直接複製的派工文字

目前 P00～P02 已完成，P03、P04、P05 已交付待驗收。**P05 交付了五關的完整內容**：第 2～5 關的城市機制、數值與牌組，第 2、4 關後的牌組獎勵（兩選一替換，牌組維持 15 張），第 3 關的主線收手結局與進階入口，以及第 5 關的進階終幕。規則升至 `rules_version 3.0.0`——護盾只吸收同系攻擊、第 4 關護盾鏡射玩家上一次進攻的系別、關卡可再設情境修正上限。五關的 `available` 都是在通過 100 seed 模擬門檻後才打開的（矩陣見 [BALANCE.md §7.0](BALANCE.md)）。

**尚未做的事**：3.0.0 的真人試玩、時長紀錄與操作錄影；手機實機；獎勵後的牌組未單獨掃 seed；P06 音效尚未試聽／接入，並且未做各瀏覽器的實機視覺驗收。

每次派工仍從本文件開始，再依指定階段讀取開發計畫、狀態、相關規格及上一階段報告。不要只把「繼續開發」交給 AI，必須在派工文字中寫明階段代號與交付物。

補完 P04／P05 真人驗收的派工：

```text
請補齊 docs/phase-reports/P04.md 與 P05.md「尚未完成或未驗證」列出的真人項目：
一次從第 1 關打到第 3 關（或第 5 關）的完整真人試玩與時長紀錄、操作錄影，
以及手機實機的音效與捲動手感確認；獎勵頁與兩個終幕頁也要看手機 viewport。
不要改動規則數值；若試玩結果要求調整，先記錄卡點再依 BALANCE.md 的升版規定處理。
```

接續 P06 的派工：

```text
請依 docs/DEVELOPMENT-PLAN.md 執行 P06 正式視聽。
P05 已交付五關完整內容與 rules_version 3.0.0，機制與數值已鎖定，
素材換上去不需要改任何規則。需要的清單可由 config/game.php 取得：
15 張牌（含 P05 獎勵的四張新牌）、5 個關卡場景、5 門禁術巨物、2 種戰役終幕。
先讀 README.md、docs/DEVELOPMENT-STATUS.md、docs/AI-HANDOFF.md、
docs/ART-AUDIO-SPEC.md、docs/ASSET-SOURCES.md、docs/BALANCE.md 與
docs/phase-reports/P05.md，並讀 .ai/rules/index.md 指到的規則檔，
檢查目前工作樹並保留既有修改。
不得把圖片需求降級成 emoji、純 CSS 或文字占位，也不得宣稱生成完成但沒有檔案；
素材要附來源、授權與實際載入證據，桌機與手機都要可讀。
沒有生成工具時可以完成其他工作，但素材交付保持未完成並交接實際缺項。
交付本階段程式、必要測試與驗收證據，建立 docs/phase-reports/P06.md，
更新進度表及受影響規格。報告已完成、未驗證、限制與下一階段入口，不自動展開後續階段。
```

## 階段報告模板

在執行該階段時建立 `docs/phase-reports/Pxx.md`，目前不預先建立假完成報告。

```markdown
# Pxx 階段報告

- 日期／執行者：
- 基準 commit／分支：
- 交付 commit，或尚未提交的變更：
- 狀態：進行中／待驗收／完成／受阻
- 對應需求：R...

## 實際完成

功能、重要檔案、行為變化；圖片與音效需附檔案及畫面。

## 驗收證據

| 驗收項 | 執行方式／環境 | 結果 | 證據位置 |
|---|---|---|---|
| 逐項填寫 | 確切命令或操作 | 通過／失敗／未執行 | 輸出或截圖 |

## Boost 使用

實際版本、讀取指引、使用的工具／查詢、可用與不可用部分。

## 規格偏差與決策

原因、替代方案、影響範圍；無偏差則明寫。

## 尚未完成或未驗證

不能把預期結果當實測結果；受阻需寫可解除的條件。

## 下一位執行者

入口、必要命令、注意事項、下一階段前置條件。
```

## 文件維護原則

資料欄位以 [`DATA-CONTRACT.md`](DATA-CONTRACT.md) 為準（P02 已建立），遊戲數值以 [`BALANCE.md`](BALANCE.md) 為準（P03 建立 `1.0.0`，P04 升至 `2.0.0`，P05 升至 `3.0.0`，P08 留牌限制升至 `3.1.0`），素材以 manifest 為準；尚未建立的檔案在對應階段才產生。專案內的既定決策與陷阱記在 `.ai/rules/`，由 `.ai/rules/index.md` 對應檔案路徑。若本組初稿與實測有差異，更新原規格並留下決策紀錄，不在多份文件各維護一組矛盾數值。

報告只收錄可公開且去除秘密的證據。README 已說明 `docs/` 是 Pages 發布來源，私密環境值不可放入階段報告。
