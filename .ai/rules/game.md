---
paths:
  - 'app/Domain/Game/**'
---

# Game

## 戰鬥引擎：規則只有一份、沒有亂數
數值與公式以 docs/BALANCE.md 為準，實作在 config/game.php。改任何數值、公式或結算順序都必須升 rules_version，否則舊局重播會與原事件序列不符。

rules_version 1.0.0 的城市行為完全由關卡預告表決定，沒有亂數；重播不需保存亂數狀態。runs.seed 目前只驅動模擬策略，不要拿來讓城市擲骰。

驗證階段只讀狀態：不合法的行動在扣任何資源之前丟 InvalidActionException，所以 422 不會消耗回合或惡意。

不要在前端或策略裡複製傷害公式。需要試算就呼叫 BattleEngine::apply()（它在 copy 上運算），合法行動清單用 availableActions()／legalActions()。

防線與適應抗性一律使用命中前的值，加層在命中之後；破綻只在防線首次歸零時開啟，回補到 20 以上才重新武裝。

## Game
## 戰鬥引擎：規則只有一份，亂數只有牌序
數值與公式以 docs/BALANCE.md 為準，實作在 config/game.php。改任何數值、公式或結算順序都必須升 rules_version，否則舊局重播會與原事件序列不符。目前 2.0.0。

城市行為完全由關卡預告表決定，沒有亂數。2.0.0 唯一的亂數是牌序，而牌序完全由 (runs.seed, shuffleCount) 決定並保存在局面裡，所以重播不需要保存亂數器狀態，重整頁面也不換手牌。

引擎沒有時間概念。決策窗口的開啟與逾時判定都在 RunService（見 .ai/rules/services-game.md）；引擎只知道「這次揭牌的截止時間是什麼」與「這次是 play 還是 timeout」。不要把 Carbon::now() 帶進 app/Domain/Game。

一個回合分兩步：reveal 揭牌開窗口，play 或 timeout 結束回合，swap 在窗口內可用一次。reveal 與 swap 推進 version 但不推進回合、不讓城市行動。

BattleState::toArray() 含抽牌堆順序，是完整存檔，只給資料庫與重播。對外一律 toPublicArray()：只公開手牌、棄牌與剩餘張數。任何新的 API 回應都要走公開投影。

驗證階段只讀狀態：不合法的行動在扣任何資源之前丟 InvalidActionException，所以 422 不會消耗回合或惡意。

不要在前端或策略裡複製傷害公式。需要試算就呼叫 BattleEngine::apply()（它在 copy 上運算），合法行動清單用 availableActions()／legalActions()。

防線與適應抗性一律使用命中前的值，加層在命中之後；破綻只在防線首次歸零時開啟，回補到 20 以上才重新武裝。

卡面成本與冷卻一律從技能表讀，牌型（config/game.php 的 cards）不另存一份數值——換皮不能改動平衡。

關卡的 available 是「這一關的內容做完了沒有」，和玩家解鎖（campaigns.unlocked）是兩回事。只有通過模擬驗收的關卡才能把 available 打開。

## 3.0.0 的城市規則：同系護盾、鏡射護盾、逐關修正上限
rules_version 目前是 3.0.0（P05 五關戰役）。三條和 2.0.0 不同的規則：

1. 護盾只吸收同系的攻擊。終招沒有系別，任何一道盾都會吸收它——不設例外，否則終招會變成無視所有城市防禦的萬用解答。這條是第 2 關「換系繞盾」成立的前提。
2. 第 4 關（mirror-shade）的 adaptive_shield：沒有排定行程且回合 >= from_turn 的回合，城市架起玩家上一次進攻系別的同系護盾。它讀的是局面裡的 lastAttackElement，沒有亂數；回合表寫死的預告優先。關卡列表的靜態 forecast 在還沒有人出手時只說明規則，不假裝城市已經選好系別。
3. 關卡可以設 modifier_cap，把每一系的情境修正再夾一次（第 5 關 0.08）。三系同時採用資料時不夾，low/high 的資料情境會直接決定勝負，而玩家選不了資料。

P05 的 available 驗收歷史門檻為 planner/greedy >= 70%、planner - random >= 25 點、legacy-cycle < 80%（第 1 關 < 95%）、單系連按 < 80%、非法選擇 0。P10-3 起逐格下限由下方 P10 規則取代，且必須另查新 quick/full 難度契約；數值與矩陣以 docs/BALANCE.md 為準。

## Consecutive keep limit from P08
Since rules_version 3.1.0, a physical card kept into the next hand cannot be kept again on the immediately following turn. Keep up to 2 cards per turn and one free swap per turn remain. Persist kept_last_turn in BattleState, reject invalid keep before mutation, and keep strategies/UI aligned with the public state.

## Current game rule version
The 3.0.0 city-rules section above records the P05 baseline. Current rules_version is 3.1.0 after the P08 consecutive keep limit; consult config/game.php and docs/BALANCE.md for the current version. Old 3.0.0 runs remain readable/replayable but cannot accept new actions.

## Level phases (acts) are separate from the overhaul mechanic state
Since P10-1, BattleState::$phase / state.phase / phase_change events mean only the level MECHANIC state (stored-night standby/overhaul, others 'standard'); config key is `mechanic_states`. Level acts live in BattleState::$levelPhaseId (`level_phase_id`, null in pre-P10-1 saves = first phase) and emit `level_phase_change`. Never reuse `phase` for acts and never count overhaul_started as an act change.
Acts are configured as `level_phases` (id, label, objective, starts_when, intents, default_intent); intents live inside phases, not at level top. Triggers are only turn_gte/core_lte/flag_true/any_of, validated at load by LevelPhaseValidator (InvalidLevelConfigException). Transition happens in advanceTurn: after city response and expiry, before turn++ and next intent; at most one step per boundary, never backwards; turn_gte compares the upcoming turn. The already shown intent is never replaced and nothing else is reset.

## rules_version 4.0.0: L1/L2 three acts, turn-only triggers, old runs read-only
Since P10-2 rules_version is 4.0.0: empty-cup and noon-fold have three level_phases triggered only by turn_gte, so the static forecast (LevelDefinition::scheduledPhaseForTurn, forecast[].level_phase_id) matches live runs exactly. A level that adds core_lte/flag_true acts must decide how the forecast labels conditional acts. noon-fold act-1 heat shield is deliberately NOT interruptible (act 1 teaches switching element). Older-version runs are read/replay only: actions, retry AND counterfactual return 409 rules_version_mismatch. LevelPhaseEquivalenceTest::CHANGED_IN_4_0_0 lists levels whose 3.1.0 recordings no longer apply; add a level there when you change it.

## P10 strategy acceptance replaces the historical P05 floor
Since P10-3, the P05 per-scenario 70% floor is superseded by DifficultyReport::PLANNER_CELL_FLOOR = 0.55 (P10 plan §3.4). StrategyMatrixTest applies it to planner and the second clearing strategy; weighted planner/forecast-aware/recovery bands, weak-strategy checks and adjacent difficulty deltas still require quick/full difficulty reports. Do not treat the cell floor alone as balance acceptance. rules_version 5.0.0 adds meter-feast turn-only acts at turns 1/4/7; versions through 4.0.0 remain read/replay only.

## P10-3 repair 5.0.1: resource diagnostics and unchanged gates

rules_version 5.0.1 changes only meter-feast turn 4 to interruptible water repair 14 (was heat repair 18). Versions through 5.0.0 are read/replay only; action, retry and finished-run counterfactual must reject version mismatch. An in-progress counterfactual still rejects run_in_progress first.

Simulation ultimate timing comes only from real sigil_spent events with reason ultimate_consumed_all, using the pre-action turn. No ultimate means an empty turn list and null average, never turn zero. Terminal malice comes only from finished engine state. JSON/CSV pool raw counts/sums before averaging: ultimate use rate divides by all games; first timing by ultimate games; winning timing by winning ultimate games; terminal malice by terminal games (and separately terminal wins/losses). Pulse windows count settled player actions on advertised pulse turns; repair windows count settled actions facing a repair intent, including lethal actions/timeouts. Pulse refunds and repair interruptions use actual events. Empty denominators remain null. Diagnostics do not change p10-di-3 weights, gates, strategies or mistake semantics. See docs/phase-reports/P10-3.md §8 for full evidence, scan history and deck disparity limitation.

## P10-4: mirror followups and 6.0.0

mirror-shade has reflection/borrowed-shadow/counter-exam at turns 1/4/7, nine turns, mirror shield 28 and modifier_cap 0.04. Scheduled repairs at 4/6/8/9 override mirroring; static forecasts explain the saved previous-attack rule without pretending to know the future element. Versions through 5.0.1 remain read/replay only. Add changed levels to LevelPhaseEquivalenceTest::CHANGED_SINCE_RECORDING, never rewrite the old recording.

Only count a lure after an actual city_shield on a mirrored turn matches saved lastAttackElement. Attribute the immediate next settled action against that still-standing shield: switched elemental core impact counts a hit; cue.impact.breach counts high impact. Static shields, cancelled mirrors, ultimates and timeouts cannot count as switched hits; reveal/swap preserve pending observation. Pool counts in JSON/CSV, and report denominators and exceptions without inferring intent or causal necessity. These diagnostics never change p10-di-3, strategy decisions, mistake semantics or gates.

## P10-5: conditional acts and two-element overhaul in 7.0.0

stored-night uses county-alert / overhaul-warning / last-night. Warning starts at turn 4 or overhaul_started; last-night starts only after overhaul_stopped or overhaul_completed, never on a fixed turn while the countdown is still active. The completed flag is written by the actual repair response. Static forecasts explicitly identify their untriggered baseline; live saved intents remain authoritative.

Matching overhaul disruptions record distinct-element progress. Only the required two distinct elements stop the overhaul; a single matching disruption on its due turn cannot defer repair. The current shown city response settles before starting the next countdown/act, including when an ordinary intent was interrupted. Lethal player damage finishes before city repair; terminal turns never create a further act/turn. Both stop/completion keep overhaul_started, so there is no second overhaul. Versions through 6.0.0 are read/replay only. This mechanic clarification does not change skills, player strategies, mistake semantics or p10-di-3.