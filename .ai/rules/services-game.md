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

## Services Game
## 對局提交：去重、版本、擁有者隔離與時鐘
每次行動都在單一交易內完成：lockForUpdate 讀 run → 查 (run_id, action_id) 去重 → 比對 expected_version → 套時鐘 → 引擎結算 → 原子寫入 run_actions 與 runs。

同 action_id 同 payload 重送回放原紀錄，不重新結算（即使該局已結束）；同 action_id 不同 payload 回 409 action_id_reused；expected_version 過期回 409 stale_version；規則不合法讓 InvalidActionException 冒出去轉 422，交易不寫入。

不是自己的局一律 404，不要用 403——403 會洩漏這個 run 存在。擁有者只能來自 HttpOnly session 的 anonymous_id。

開局才解析快照組並凍結進 runs.snapshot_ids；回合中絕不呼叫 OpenData adapter。

RunService 是唯一的時鐘（resolveAgainstClock）。截止時間由伺服器在 reveal 時決定並寫進局面，客戶端聲稱的點擊時間一概不採信。到達截止時間（含相等）才抵達的 play 或 swap 改判為同一筆 timeout，保留原 action_id 與原指紋——遲到的牌不會被施放，整個回合只結算一次。指紋算在客戶端送來的原始 payload 上，不是收斂後的，重送才會拿回同一個結果。

ActionRequest 的指紋不含 deadlineAt：那是伺服器決定的，列進去會讓同一次 reveal 重送被誤判成「不同 payload」。keep 在建構時正規化（去重＋排序），所以送出順序不同不算不同 payload。

唯讀端點不得收斂逾時、不得建立戰役。逾時要靠客戶端請求或下一次有效寫入來結算，Hostinger 不新增常駐 worker。

練習模式只關掉截止時間，其餘規則完全相同；解鎖與最佳表現寫在 campaigns 的 practice_unlocked／practice_results，練習通關不得寫進挑戰軌。
