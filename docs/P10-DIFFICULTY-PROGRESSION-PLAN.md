# P10 正式上線後難度曲線與關卡三幕化計畫

- 建立日期：2026-10-05
- 狀態：P10-0、P10-1、P10-2 已交付待驗收（[P10-0](phase-reports/P10-0.md)、[P10-1](phase-reports/P10-1.md)、[P10-2](phase-reports/P10-2.md)）；P10-2.1、P10-3 已交付待驗收（[P10-3](phase-reports/P10-3.md)，原 5.0.0 full 未達，5.0.1 補修已具備本包自動驗收條件）；P10-4 起尚未動工；現行 `rules_version 5.0.1`
- 基準：`rules_version 3.1.0`、五關正式版已部署
- 目的：修正五關難度沒有穩定遞增、每關只有單段戰鬥而缺少內部節奏的問題
- 範圍：Laravel／Vue 正式版；`docs/` GitHub Pages 歷史原型不修改

## 1. 問題判定

現有機器矩陣只驗證「可解」、「規劃策略比隨機好」、「至少兩種策略可過」，沒有驗證第 1 關必須比第 2 關容易、第 5 關必須最難。因此目前結果合法通過測試，卻不構成難度曲線。

現行 3.1.0 的 planner 勝率也直接顯示這個缺口：中性情境約為第 1 關 100%、第 2 關 94%、第 3 關 100%、第 4 關 100%、第 5 關 100%；後關不一定比前關難。高情境更幾乎全部滿勝率。

第二個缺口是 `LevelDefinition::$phases` 目前只是字串陣列，除第 5 關的重整狀態外，沒有真正的通用階段定義、切換條件、階段預告、階段目標與驗收。五關雖各有一個獨特機制，但一場對局基本上仍是一段到底。

第三個缺口是模擬情境過於簡化：只有三系一起低、一起中、一起高；沒有混合資料情境，也沒有把第 2、4 關獎勵後的實際牌組納入矩陣。

## 2. 核心設計決策

1. **保留 3 關主線＋2 關進階，不新增十個獨立選關。** 每關改成三幕，一局內連續推進，不破壞現有戰役、匿名存檔、獎勵與終幕。
2. 每一關遵循「複習既有能力 → 單獨介紹本關機制 → 與前面機制組合考核」。後關必須累積前關所學，不能只把上一關的機制換掉。
3. 難度優先來自需要觀察、規劃、留牌、換系、打斷與掌握窗口；不得只加核心血量、全面提高防線或砍玩家傷害。
4. 所有致命機制必須預告。後期可以縮短容錯與把兩種已知機制交錯，但不能用隱藏規則或無預警必敗製造難度。
5. 任何規則、數值、階段切換或結算順序變更均需升 `rules_version`；P10-2 首次升為 4.0.0，P10-3 升為 5.0.0，本補修升為 5.0.1（第 4 回合可打斷水修復 14）。舊版本局保持可讀、可重播、不可繼續結算、重試或反事實比較。
6. 先建立新的量測與驗收契約，再調數值；不能邊改關卡邊用感覺宣稱難度已遞增。

## 3. 新難度契約

### 3.1 模擬情境

快速回歸矩陣使用 9 組代表情境：中性、全低、全高，以及水／熱／土地各自單獨取低或高、其餘中性的 6 組混合情境。正式發布前再跑三系 `{-15%, 0, +15%}` 的 27 組完整排列。

牌組也必須使用玩家實際可能持有的版本：

| 關卡 | 必掃牌組 |
|---|---|
| 第 1、2 關 | 起始 15 張牌組 |
| 第 3、4 關 | 第 2 關後的兩種獎勵選擇 |
| 第 5 關 | 第 2 關兩種 × 第 4 關兩種，共 4 種組合 |

快速 CI 每格至少 100 seeds；正式平衡報告每格至少 300 seeds。全量報告不必每次提交都跑，但正式部署與規則版本發布前必須重跑。

### 3.2 策略樣本

保留 `random`、`single-water`、`legacy-cycle`、`greedy`、`planner`，並新增：

- `forecast-aware`：只讀玩家目前可見的手牌、當前預告、資源與階段，不做完整後續試算，代表理解規則但沒有最佳化的玩家。
- `planner-one-mistake`：大致依 planner 行動，但在本關核心機制上注入一次**明確可辨識的錯誤處理**，用來量測關卡的恢復空間。只承認兩種失誤，且兩者都要求「planner 的最佳打法本身就是正確處理」——少了這個條件，另一條同樣合理的策略會被誤標成失誤：
  - `missed_interrupt`：預告是可打斷的修復／護盾／重整，手上有同系擾序，planner 最佳打法就是去打斷，卻改成不處理那個預告的合法行動。
  - `walked_into_shield`：場上有同系護盾，planner 最佳打法繞開了它，卻改成會被那道盾吸收的攻擊。

  從第一回合起逐回合檢查，第一個能構成明確機制錯誤的回合就注入，每局最多一次；整局都找不到就記 `no_eligible_mistake`，不硬塞任意次佳動作，也不為個別 seed 特判。判定只讀玩家當下看得見的資訊（預告、站著的護盾、手牌），不讀未抽牌序。`reinforce` 不列入可打斷窗口——它只是補防線，不是關卡要教的機制。
- `solver`：離線使用完整確定性狀態與固定 seed 搜尋通關路徑，只用來證明該 seed 存在解；它可以查看內部牌序，但不得被當成人類策略或難度指標。

### 3.3 量測欄位

`SimulationResult` 與報告至少增加：是否通關、結束回合、勝利時使用的回合比例、失敗剩餘核心、城市實際修復量、護盾吸收量、成功／失敗打斷次數、破綻浪費次數、各幕到達與離開回合、本關核心機制觸發及正確處理次數、牌組版本。

以四個透明指標形成內部 `difficulty_index`，不得只看單一勝率：

```text
difficulty_index =
    0.35 × (1 - planner_win_rate)
  + 0.25 × (1 - forecast_aware_win_rate)
  + 0.25 × (1 - one_mistake_recovery_rate)
  + 0.15 × average_winning_turn_budget_used
```

這個分數只供自動排序與回歸，不取代真人試玩。公式或權重若修改，報告要留下版本與理由。

`INDEX_VERSION` 目前是 **`p10-di-3`**（P10-2.1）。公式與權重從未改過，變的是第三項「一次失誤」的語意，因此不同版本的分數不可互相比較：

| 版本 | 階段 | 第三項語意 |
|---|---|---|
| `p10-di-1` | P10-0 | planner-one-mistake 的**無條件勝率**；失誤可能只是同招的另一張實體牌 |
| `p10-di-2` | P10-0.1～P10-2 | 同 seed 配對恢復率；失誤只要求「語義不同且 planner 分數嚴格較低」 |
| `p10-di-3` | P10-2.1 起 | 同 seed 配對恢復率；失誤必須是上述兩種**核心機制錯誤**之一 |

升到 `p10-di-3` 的理由：`p10-di-2` 的定義在第 1、2 關實測有約七成注入的「失誤」不是機制錯誤，只是比較弱的合法打法；第 2 關甚至出現「planner 最佳是直接輸出、注入的失誤卻是正確打斷水盾」的反向標記。證據與新舊對照見 [P10-2.1 報告](phase-reports/P10-2.1.md)。

### 3.4 初始發布門檻

| 關卡 | planner 加權勝率目標 | forecast-aware 目標 | 一次失誤後仍通關目標 | 定位 |
|---|---:|---:|---:|---|
| 1 | 95–100% | 80–95% | 75–90% | 教學與建立信心 |
| 2 | 88–95% | 65–82% | 60–75% | 開始要求讀盾與換系 |
| 3 | 80–90% | 45–68% | 42–60% | 資源與窗口調度 |
| 4 | 70–82% | 25–52% | 25–45% | 城市反制玩家習慣 |
| 5 | 60–75% | 10–38% | 10–30% | 綜合畢業考 |

另有四條硬門檻：

1. 五關 `difficulty_index` 必須依序上升，相鄰至少增加 0.05；不能只靠四捨五入形成排序。
2. `solver` 在正式 27 情境 × 合法牌組 × 發布 seeds 中必須 100% 找到通關路徑；找不到的 seed 不得發布。
3. planner 任一情境／牌組格不得低於 55%，避免某種玩家無法控制的資料與獎勵組合突然變成斷崖。P10-3 起 `DifficultyReport::PLANNER_CELL_FLOOR` 是共用來源，取代 P05 三情境測試的 70%；舊測試的第二種可行策略也沿用 55%。這不取代逐關加權區間、難度遞增與弱策略檢查，不把 full greedy 55% 新增為發布門檻。
4. 從第 2 關起，`random`、單系連按及固定舊循環不得因高情境或獎勵牌組變成穩定解法；任一格達 50% 即需人工檢查。

## 4. 五關三幕設計

所有幕切換都在當前城市回應完成後、下一回合預告產生前發生。已經展示給玩家的預告不得因核心下降或旗標改變而臨時換掉。幕切換不重置核心、防線、手牌、牌堆、惡意、印記、抗性、冷卻、護盾或 seed。

| 關卡 | 第一幕：複習／觀察 | 第二幕：本關機制 | 第三幕：組合考核 |
|---|---|---|---|
| 1 枯潮 | **試杯**：前 2 回合只補強防線，讓玩家看懂預告、出牌與核心傷害 | **斷補**：出現第一個明確水系修復窗口；成功打斷仍給既有惡意返還 | **空杯小考**：第二個更強修復窗口，要求玩家在打斷與輸出間取捨；允許一次失誤後仍有補救空間 |
| 2 折晝 | **單盾示範**：只出現一種同系護盾，清楚展示換系可繞盾 | **輪班防線**：護盾在三系輪替，玩家要留牌或改系 | **交錯窗口**：輪替護盾與第 1 關學過的修復交錯；必須決定要打斷、繞盾或保存高衝擊牌 |
| 3 饗表 | **帳前準備**：用熟悉的護盾／修復回顧前兩關，但壓力尚低 | **需求脈衝**：脈衝回合鼓勵跨系連攜，惡意開始成為規劃資源 | **高峰結算**：脈衝、修復、護盾密集交錯；要為終招和關鍵打斷保留惡意與手牌，完成主線考核 |
| 4 鏡蔭 | **照影**：城市只鏡射上一次進攻系別，預告完整，讓玩家學會誘盾再換系 | **借影**：鏡射護盾加入排定修復，固定循環會被針對 | **反制考核**：鏡射、修復與較短的有效輸出窗口交錯；至少要成功完成一次「誘盾 → 換系高衝擊」才有穩定勝率 |
| 5 蓄夜 | **全縣戒備**：使用前四關已知的盾、修復與換系壓力，不再教基本操作 | **重整警報**：核心或回合到門檻後啟動兩回合重整；玩家需用兩種不同系擾序中止，或在倒數完成前結束 | **最後一夜**：重整中止或完成後進入終局，保留盾／修復壓力但不再重複開第二次重整；考驗剩餘牌組、冷卻、惡意與終招窗口 |

### 4.1 階段設定契約

新增明確的 `LevelPhaseDefinition`（名稱可依現有 conventions 調整），取代目前只有字串的 `phases`。設定只支援本次真的需要的有限觸發，不建立任意腳本 DSL：

```php
'phases' => [
    [
        'id' => 'opening',
        'label' => '第一幕・試杯',
        'starts_when' => ['type' => 'turn_gte', 'value' => 1],
        'objective' => '先看懂城市預告與核心傷害。',
        'intents' => [...],
        'default_intent' => [...],
        'mechanics' => [...],
    ],
]
```

允許的觸發型別初版只包含：`turn_gte`、`core_lte`、`flag_true`，以及明確的 `any_of`；不允許執行任意 PHP callback。相位按設定順序單向前進，不可回退。

每次切換必須產生 `phase_change` 事件，至少包含 `from`、`to`、`reason_code`、`objective`。`BattleState.phase` 繼續保存穩定 phase ID；完整存檔與公開投影都能還原目前幕次。

### 4.2 前端呈現

戰鬥畫面加入不遮擋手牌的「第幾幕／本幕目標／本幕新壓力」區塊。幕切換只播放短提示，不占用下一回合 30 秒。完整預告仍顯示每回合會發生什麼，不能只寫抽象名稱。

戰報增加：玩家在哪一幕結束、各幕是否正確處理核心機制、哪一幕開始失去節奏。不得用「進入第三幕」本身當作失敗原因，仍需引用實際修復、護盾、逾時、打斷與傷害事件。

### 4.3 P10-1 已實作的幕次契約（2026-10-05）

P10-1 建立了下列契約；P10-2 起的三幕內容必須沿用，改動需先更新本節與測試。

**設定（`config/game.php`）**

```php
'level_phases' => [
    [
        'id' => 'main',                    // 穩定 ID，同一關不可重複
        'label' => '全關',                 // 顯示名稱
        'objective' => '…',               // 本幕目標（公開）
        'starts_when' => ['type' => 'turn_gte', 'value' => 1],
        'intents' => [3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 14, 'interruptible' => true]],
        'default_intent' => ['type' => 'reinforce', 'element' => 'water', 'magnitude' => 4, 'interruptible' => false],
    ],
],
'mechanic_states' => ['standby', 'overhaul'],   // 原 'phases'：只給第 5 關重整機制用
```

- 預告表與預設意圖在**各幕之內**，關卡頂層不再有 `intents`／`default_intent`。
- 觸發條件只有 `turn_gte`（即將開始的回合 ≥ value）、`core_lte`（核心 ≤ value）、`flag_true`（`first_interrupt_done`、`overhaul_started`、`overhaul_stopped`，且必須是這一關機制真的會設定的旗標）、`any_of`（非空子條件）。不接受其他欄位或型別。
- 載入時由 `LevelPhaseValidator` 檢查；不合法即丟 `InvalidLevelConfigException`（reason code：`phase_missing_first`、`phase_duplicate_id`、`phase_first_not_initial`、`trigger_unknown_type`、`trigger_unknown_field`、`trigger_field_type`、`trigger_unknown_flag`、`trigger_flag_not_settable`、`trigger_empty_any_of`、`phase_order_regression`、`phase_unreachable`、`phase_missing_default_intent`、`intent_invalid`、`intent_turn_out_of_range`）。
- 驗證器只判定**結構上不可達**（回合超過上限、`core_lte` < 1、這關不會設定的旗標）；`core_lte`／`flag_true` 在某一局是否真的會到達屬執行期，不是設定錯誤。

**狀態與事件**

| 概念 | 欄位 | 事件 |
|---|---|---|
| 關卡幕次 | `BattleState::$levelPhaseId`／`state.level_phase_id`（舊存檔為 null，視為第一幕） | `level_phase_change`：before `{level_phase_id}`、after `{level_phase_id, label, objective, from_turn}`、reason_code `level_phase_<trigger type>` |
| 機制狀態（第 5 關重整） | `BattleState::$phase`／`state.phase`（standard／standby／overhaul，語意不變） | `phase_change`：`overhaul_started`／`overhaul_stopped`／`overhaul_completed`（不變） |

切幕時點固定在 `advanceTurn()` 開頭：玩家行動 → 城市回應 → 效果期限 → **判定切幕並記錄事件** → 回合 +1 → 產生下一回合預告。每個邊界最多前進一幕，不回退。已展示的本回合預告不替換；切幕不重置任何數值、手牌、牌堆、護盾、seed 或截止時間語意。

**對外 API**

- `GET /api/v1/levels`：每關新增 `phases`（`id`、`order`、`label`、`objective`、`starts_when`、`starts_when_summary`、`next_phase_summary`）。
- `GET /api/v1/runs/{run}`：新增 `level_phase`（同上欄位＋`total`），`state` 新增 `level_phase_id`。既有欄位語意不變。
- 多幕關卡的靜態 `forecast`（P10-2）：每回合取該回合所在幕的預告表並帶 `level_phase_id`；所在幕只依回合門檻推進（`LevelDefinition::scheduledPhaseForTurn()`），核心與旗標條件視為未成立。只用 `turn_gte` 的關卡因此和實際對局一致；用到 `core_lte`／`flag_true` 的關卡要另外標示條件幕。

**模擬量測**：`SimulationResult::$phaseChanges` 只記機制狀態，`$levelPhaseChanges` 只記幕次。P10-2 起難度報告每格有 `avg_level_phase_changes` 與 `level_phases[]`（`reach_rate`、`avg_entry_turn`），CSV 為 `act_1～3_*` 欄位。`difficulty_index` 的公式與權重自 `p10-di-1` 以來從未改過；第三項的語意在 P10-2.1 改版並升為 `p10-di-3`，版本差異見 §3.3。

**可解性 solver**：`php artisan game:solve`（`App\Domain\Game\Solver\SolvabilitySolver`）。見 [P10-1 報告](phase-reports/P10-1.md)。

## 5. AI Agent 執行工作包

工作包預設依序執行。每包完成後要留下獨立 commit、階段報告、測試輸出及下一包入口；不得把「規劃完成」當成功能完成。開始前一律讀 `README.md`、`AGENTS.md`、`.ai/rules/index.md`、`docs/AI-HANDOFF.md`、`docs/BALANCE.md`、`docs/DEVELOPMENT-STATUS.md` 與本文件，並先確認工作樹。

### P10-0｜難度量測與 3.1.0 基線

**目的：**在不改規則的前提下，建立能看見難度曲線的資料。

**主要修改：**

- 擴充 `SimulationResult`、`BattleSimulator` 與模擬命令，或新增 `game:difficulty-report`。
- 加入 9 組快速情境、27 組完整情境、合法獎勵牌組展開與 JSON／CSV 摘要。
- 新增 `forecast-aware`、`planner-one-mistake`；`solver` 若本包無法可靠完成，可明列為 P10-1 前置，但不能以 planner 冒充可解性證明。
- 保存現行 3.1.0 的完整基線，不改 `config/game.php`、技能數值、城市意圖或 `rules_version`。

**交付：**`docs/phase-reports/P10-0.md`、可重跑命令、基線摘要與至少一份機器可讀結果。

**驗收：**相同 commit、參數及 seed 產生相同結果；報告能按關卡／情境／牌組／策略聚合；清楚顯示現行曲線不單調。既有測試全綠。

### P10-1｜三幕引擎與設定驗證器

**目的：**先建立通用而有限的階段能力，不立即重新平衡五關。

**主要修改：**

- 建立 typed phase definition／resolver；讓 `LevelDefinition` 驗證 phase ID、順序、觸發、意圖及預設意圖。
- 在固定結算點執行單向 phase transition，產生 `phase_change` 事件；已展示的當回合 intent 不得被替換。
- 先把每關包成單一等價 phase，證明與 3.1.0 的代表 seed 事件序列一致。這個等價重構本身不升版。
- 加入錯誤設定測試：重複 ID、無第一幕、未知觸發、倒退、缺 default intent、無法到達的幕。
- 固定對外 phase API 契約，供前端工作使用。

**主要檔案：**`app/Domain/Game/LevelDefinition.php`、新 phase 類別、`BattleState.php`、`BattleEngine.php`、`config/game.php`、對應測試。

**交付：**`docs/phase-reports/P10-1.md`，包含舊行為等價證據與新契約。

**驗收：**代表性的五關 × 三情境 × 固定 seeds 在單一 phase 設定下，其勝負、事件與狀態投影不變；phase 轉換測試涵蓋動態觸發、重播與存檔還原。

### P10-2｜第 1、2 關三幕化與教學曲線

**目的：**建立清楚的「教學關 → 第一個真正策略關」差異。

**主要修改：**

- 依第 4 節實作第 1、2 關三幕，更新 briefing、完整預告、phase objective 與戰報規則。
- 第 1 關允許一次明顯錯誤後仍可補救；第 2 關開始要求盾系判讀與換系，不可只靠固定循環。
- 第一個實際規則變更時將 `rules_version` 升為 `4.0.0`，補 3.1.0 舊局只讀／重播測試。
- 只調本兩關必要數值，不提前修改第 3～5 關來湊整體曲線。

**交付：**`docs/phase-reports/P10-2.md`、兩關的 phase 設定、機制測試、快速矩陣與可重現勝敗樣本。

**驗收：**第 1、2 關各三幕都實際到達且有事件；符合各自目標區間；第 2 關 `difficulty_index` 至少比第 1 關高 0.05；兩關所有正式抽樣 seed 可解。

### P10-3｜第 3 關三幕化與主線難度頂點

2026-10-05 原交付 `7a056a3`／5.0.0 的歷史：full planner 83.85%、恢復率 47.97%、L2→L3 +0.0672 通過，forecast-aware 70.30% 高於上限 68%，保持待驗收。數值保留，不能以 quick 全過宣稱 full 平衡完成。代表 solver 120／120，不等於 P10-7 全量證明。詳見 [P10-3 報告](phase-reports/P10-3.md)。

本輪 5.0.1 最小補修：僅將第 4 回合熱修復 18 改為水修復 14；full 27 情境 × 兩種實際獎勵牌組 × 300 seeds：planner **84.85%**（最低格 **65%**）、forecast-aware **64.65%**、配對失誤恢復 **44.53%**（覆蓋率 **99.83%**）、difficulty_index **0.3967**、L2→L3 **+0.0886**，全部原定 gate 通過。quick 同樣通過，solver 120／120 可解且重播，266 後端測試通過，狀態維持待驗收（本包自動驗收條件已具備）。新增真實事件／終局狀態的終招時機、終局惡意及機制使用診斷，null 與分母見報告 §8；不改 p10-di-3、策略、失誤語意或 gate。煙囪優勢已反轉，兩牌組絕對差距擴大，不能宣稱差距縮小。原失敗及九候選留存；第 1 關既有缺口保留。本包結束即停止，不開始 P10-4、不部署。

**目的：**讓主線最終關真正考驗前兩關知識與惡意／終招資源，而不是因防線較低反而更容易。

**主要修改：**

- 實作帳前準備、需求脈衝、高峰結算三幕。
- 把第 2 關後兩種獎勵牌組都納入調整；不可只用起始牌組驗收。
- 量測脈衝回合的連攜使用率、修復打斷率、終招時機及剩餘惡意。
- 保持主線通關、收手與進階入口契約不變。

**交付：**`docs/phase-reports/P10-3.md`、第 3 關快速與完整矩陣、兩種獎勵牌組的勝敗樣本。

**驗收：**第 3 關比第 2 關至少高 0.05 difficulty index；planner 與 forecast-aware 落在目標區間；兩種獎勵牌組都可解且沒有一種把固定套路推成穩定解法。

### P10-4｜第 4 關三幕化與自適應反制

**目的：**讓進階第一關明顯高於主線，但所有反制仍可預測、可誘導。

**主要修改：**

- 實作照影、借影、反制考核三幕。
- 增加「誘出同系護盾後換系命中」的可量測事件或分析欄位；不能只從最終勝負猜玩家是否理解。
- 鏡射依舊只讀已保存的 `lastAttackElement`，不得偷看未抽牌或玩家將要出的牌。
- 第 2 關獎勵的兩種牌組都需通過；保留第 4 關結束後的獎勵契約。

**交付：**`docs/phase-reports/P10-4.md`、鏡射與 phase 交互測試、完整矩陣。

**驗收：**第 4 關比第 3 關至少高 0.05 difficulty index；固定循環與只看當回合傷害的打法顯著下降；planner 仍能穩定利用可見預告，不依賴隱藏資訊。

### P10-5｜第 5 關三幕化與綜合畢業考

**目的：**建立全戰役最難但公平的一關，綜合前四關，不靠第二次突然回血拖長戰鬥。

**主要修改：**

- 實作全縣戒備、重整警報、最後一夜三幕。
- 重整觸發、兩種不同系擾序、中止後全系破綻與終局行為必須有獨立測試。
- 第 2、4 關的 4 種獎勵組合全部進矩陣；找出任何過強或過弱組合並調整關卡，不任意改掉玩家已選獎勵。
- 第三幕不得再啟動第二次完整重整；難度來自殘餘資源、城市壓力和最後窗口。

**交付：**`docs/phase-reports/P10-5.md`、4 種牌組 × 全情境結果、至少兩條不同通關路線與典型失敗路線。

**驗收：**第 5 關 difficulty index 全戰役最高且比第 4 關至少高 0.05；solver 全部可解；planner 任一格不低於 55%；任一獎勵組合不得讓 legacy／single-element 穩定通關。

### P10-6｜三幕 UI、預告與戰報

**目的：**讓玩家真的看得懂關卡已進入哪一幕、為何壓力改變，而不是只有後端 phase 字串。

**主要修改：**

- 更新 API Resource／TypeScript type，顯示目前幕次、名稱、目標與下一幕條件的可公開部分。
- 戰鬥畫面顯示三幕進度；`phase_change` 短演出可跳過、可減少動態，且不吃決策時間。
- briefing 用三行說明本關三幕，不一次塞完整解法；完整預告仍可展開。
- 戰報按幕整理實際事件，指出哪個機制有處理、哪個機制造成損失。
- 320、390、桌機與鍵盤均需檢查，不能讓新增幕次資訊重新壓縮卡牌文字。

**交付：**`docs/phase-reports/P10-6.md`、桌機／手機 viewport 證據、前端測試與五關 phase 顯示樣本。

**驗收：**玩家不看程式碼即可回答目前第幾幕、本幕目標、城市下一步；phase 切換不誤出牌、不重設倒數、不遮擋核心操作。

### P10-7｜全曲線驗收、真人試玩與正式發布

**目的：**把個別關卡調整收斂成真正的五關曲線，並安全替換已部署的 3.1.0。

**自動驗收：**

- 快速 9 情境矩陣加入 CI／必要回歸；正式 27 情境 × 合法牌組 × 300 seeds 產生發布報告。
- 新增 `DifficultyProgressionTest`，直接斷言 difficulty index 逐關增加、目標區間、可解性、牌組差異與三幕到達率。
- 跑完整 PHP、TypeScript、前端邏輯、建置、Pint、重播、反事實比較、併發與舊局相容測試。

**真人驗收：**至少 5 位未參與開發者，在不口頭教學下從第 1 關依序玩到可到達的後續關卡；記錄每關嘗試次數、通關回合、卡住的幕、是否理解失敗原因、是否感到後關更難。樣本小，不宣稱統計顯著，但若多數人認為後關更簡單，不能只用機器分數結案。

**部署：**先備份正式 SQLite 與 `.env`，部署最終鎖定規則版本的程式與前端產物（目前 5.0.1），執行必要 migration／cache，確認 3.1.0 進行中舊局顯示唯讀與重新開局說明。冒煙測試五關入口、幕切換、勝敗、獎勵、主線／進階終幕、排程與回退。保留前一版發布包，發現曲線或存檔問題可整版回退，不直接在正式機手改數值。

**交付：**`docs/phase-reports/P10-7.md`、更新後 `BALANCE.md`、`QA-REPORT.md`、`DEVELOPMENT-STATUS.md`、正式發布與回退紀錄。

## 6. 必守護欄

- 不得為了讓測試通過而放寬本文件門檻；需要調整門檻時，先在階段報告列出實測、原因與對玩家的影響。
- 不得挑選有利 seed 或只報平均值。最低格、最高格及失敗格都要列出。
- planner、forecast-aware、greedy 及其他模擬玩家策略只能讀玩家可見狀態；未抽牌序、未來 seed 與資料庫內部欄位不得用於決策。只有離線 solver 可使用完整確定性狀態做存在解證明，且報告必須與人類策略完全分開。
- 不得以增加隨機性製造難度。牌序以外仍保持確定性，重播與反事實比較必須一致。
- 不得在 Vue 複製傷害、phase 或城市意圖規則；前端只呈現伺服器狀態與事件。
- 不得因本次工作改成 MySQL、Redis、WebSocket、常駐 queue、Node server 或 Docker 才能執行；Hostinger 共享空間限制不變。
- 不修改 `docs/index.html`、`docs/app.js`、`docs/game.js`、`docs/style.css` 歷史 Pages 核心檔案。
- 未經明確授權不部署、不 push、不改正式資料庫；每個工作包完成後停止，等下一次派工。
- 不使用 `git reset --hard`、不清除他人變更、不覆蓋未提交檔案來取得乾淨工作樹。

## 7. 每包共同驗證命令

依實際新增命令調整名稱，但階段報告必須列出確切執行內容：

```sh
git status --short --branch
php artisan test --compact
php artisan test --filter=StrategyMatrixTest
php artisan test --filter=DifficultyProgressionTest
php artisan game:simulate --seeds=100
php artisan game:difficulty-report --suite=quick --seeds=100
npm run typecheck
npm run probe:test
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
```

正式發布門檻另跑：

```sh
php artisan game:difficulty-report --suite=full --seeds=300 --json=output/p10/full-report.json
```

若命令尚未在該工作包建立，報告應標「尚不存在／由哪一包建立」，不可假裝已執行。

## 8. 提交與合併順序

建議從目前正式版本建立 `feat/p10-difficulty-progression`，每個工作包一個可獨立審查的 commit 或短分支。P10-0、P10-1、P10-2、P10-3、P10-4、P10-5 預設串行，因為都會碰模擬契約、`config/game.php` 或戰鬥引擎；不要讓多個 Agent 平行修改這些核心檔。

P10-6 只能在 P10-1 的 API 契約固定後平行進行，而且不得自行推測尚未定案的 phase 欄位。P10-7 必須在前面所有包合併後執行。

每個 commit 只包含該包工作。階段報告需記錄基準 commit、交付 commit、規則版本、修改關卡、快速／完整矩陣是否執行，以及仍未驗證項目。

## 9. 完成定義

只有同時符合以下條件，才能說「難度曲線與關卡細分完成」：

1. 五關每關都有三個可到達、可理解、會實際改變城市行為的幕，而不是只換標題。
2. 9 情境快速矩陣成為回歸測試，27 情境與合法獎勵牌組有正式發布報告。
3. 自動 difficulty index 依序上升，solver 可解性與各策略門檻通過。
4. 後關累積使用前關機制；第 5 關是綜合考，不是單純血厚。
5. UI、完整預告、phase 事件、重播、戰報與反事實比較都能識別三幕。
6. 至少 5 位真人試玩記錄支持「後關整體較難且失敗原因可理解」；沒有這份證據時狀態只能是待驗收。
7. 最終鎖定規則版本正式部署、舊局處理、備份、冒煙測試及回退演練完成。

## 10. 可直接複製的 Agent 派工文字

每次只替換工作包代號與名稱，不要把 P10-0～P10-7 一次全部交給同一個 Agent：

```text
請執行 docs/P10-DIFFICULTY-PROGRESSION-PLAN.md 的「P10-X｜工作包名稱」，只完成這一個工作包，完成後停止，不要自動接下一包，也不要部署或 push，除非本次另外明確授權。

開始前先讀 README.md、AGENTS.md、docs/AI-HANDOFF.md、docs/DEVELOPMENT-STATUS.md、docs/BALANCE.md、docs/P10-DIFFICULTY-PROGRESSION-PLAN.md、上一個 P10 階段報告，以及 .ai/rules/index.md 指到的適用規則。檢查目前分支、git status、基準 commit 與既有未提交變更；不得清除、重設或覆蓋別人的修改。

依該工作包列出的目的、主要修改、交付與驗收逐項實作。規則與結算變更遵守 rules_version 與舊局相容規定；客戶端不得重算遊戲規則；保持 Laravel + Vue + SQLite、Hostinger 共享空間及確定性重播約束。不要用單純加血、挑有利 seed、放寬門檻或讀取隱藏牌序來製造通過結果。

建立 docs/phase-reports/P10-X.md，記錄基準 commit、實際修改、確切測試命令與輸出、難度矩陣摘要、未驗證項目、規格偏差與下一包入口。更新本包直接影響的權威文件，但不要預先把後續工作標為完成。完成後執行適用的完整回歸、git diff --check，提交一個範圍清楚的 commit；若未獲提交授權，保留變更並提供建議 commit 訊息。
```

### 第一個工作包的建議派工

```text
請執行 docs/P10-DIFFICULTY-PROGRESSION-PLAN.md 的「P10-0｜難度量測與 3.1.0 基線」。本包不得修改 config/game.php 的技能、關卡、城市意圖與 rules_version；目標是先建立可重跑的難度報告、混合情境、合法獎勵牌組與新策略樣本，留下 3.1.0 現況證據。完成後建立 docs/phase-reports/P10-0.md 並停止，不要接著調整任何關卡。
```
