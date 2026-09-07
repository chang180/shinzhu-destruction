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
