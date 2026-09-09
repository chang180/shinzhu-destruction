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
