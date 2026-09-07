---
paths:
  - 'app/Services/Game/**'
---

# Services Game

## 對局提交：去重、版本與擁有者隔離
每次行動都在單一交易內完成：lockForUpdate 讀 run → 查 (run_id, action_id) 去重 → 比對 expected_version → 引擎結算 → 原子寫入 run_actions 與 runs。

同 action_id 同 payload 重送回放原紀錄，不重新結算（即使該局已結束）；同 action_id 不同 payload 回 409 action_id_reused；expected_version 過期回 409 stale_version；規則不合法讓 InvalidActionException 冒出去轉 422，交易不寫入。

不是自己的局一律 404，不要用 403——403 會洩漏這個 run 存在。擁有者只能來自 HttpOnly session 的 anonymous_id。

開局才解析快照組並凍結進 runs.snapshot_ids；回合中絕不呼叫 OpenData adapter。
