# P04 階段報告（第一版切片，已被改版取代）

> 本報告記錄 2026-09-08 的「首關可玩切片」，規則為 `rules_version 1.0.0`、不限時、全技能選單。
> 2026-09-09 的真人試玩後，P04 範圍改為限時手牌改版（[改版計畫](../P04-REVISION-PLAN.md)），
> 現行 P04 報告見 [P04.md](P04.md)。本檔保留為歷史證據，其中的驗收數字對應舊規則，
> 不可當成現行版本的證據。

- 日期／執行者：2026-09-08／Codex
- 基準 commit／分支：29ced72 / main
- 交付 commit，或尚未提交的變更：本報告與 P04 完整切片實作將提交於同一 commit
- 狀態：待驗收
- 對應需求：R1、R5A、R6

## 實際完成

- 第 1 關已有 Laravel + Vue 完整流程：學院首頁、開局簡報、戰鬥畫面、戰報、回學院與最近對局。
- 第 1 關敘事改為枯潮教授・晏沉傳授第一禁術「枯潮」，終式命名為「萬川歸寂」；`empty-cup` 只保留為存檔相容的穩定 ID。
- GET /api/v1/runs 可列出目前匿名工作階段的最近對局；POST /runs/{run}/retry 以原局的快照、情境修正與 seed 建立同情境新局。
- 對局保存快照的品質、觀測期間與警告；重播不以目前資料冒充開局資料。
- 對局讀取回傳規則相容性、可用行動與完整事件歷史；舊規則局可讀取與重送既有 action，但阻止新結算。
- 前端只讀伺服器的 available_actions、技能成本／冷卻、預告與事件；施招失去回應時以 sessionStorage 保留原 action_id 和 payload，重試同一行動。
- 事件依 cue_id 播放可跳過的圖片／聲音演出，支援靜音、音量、兩倍速、減少動態、Escape 跳過與分頁切換。
- 事件演出會依結局分流：玩家勝利用獨立的城市解體主視覺，城市守住沿用防守畫面；演出期間焦點鎖在跳過按鈕，避免鍵盤操作落到背景。
- 生成 9 張原創圖片並壓縮為 WebP，另整合 Kenney Impact Sounds CC0 候選；生成提示詞、雜湊與檢視狀態見 assets/p04-generation.json。

## 驗收證據

| 驗收項 | 執行方式／環境 | 結果 | 證據位置 |
|---|---|---|---|
| P04 API 回歸 | php artisan test --compact | 通過，112 tests／539 assertions | PHPUnit 輸出 |
| P04 新行為 | php artisan test --compact tests/Feature/Game/RunExperienceTest.php | 通過，5 tests／47 assertions | tests/Feature/Game/RunExperienceTest.php |
| PHP 格式 | vendor/bin/pint --dirty --format agent | 通過並修正必要格式 | Pint 輸出 |
| 前端型別 | npm run typecheck | 通過 | npm 輸出 |
| 前端建置 | npm run build | 通過；產物位於 public/build | Vite 輸出 |
| 素材檔案 | WebP 轉檔、SHA-256 manifest | 9 張 WebP 可讀，提示詞／雜湊見 assets/p04-generation.json | assets/p04-generation.json |
| P04 前端純邏輯／音效回歸 | node --test tests/p04-presentation.test.cjs | 通過，9 tests | tests/p04-presentation.test.cjs |
| 桌機／手機瀏覽器流程 | Herd 失效時以 `http://localhost:8000/` 驗證 | 通過：簡報、續玩、冷卻、打斷、破綻、終招、勝利戰報與 390×844 版面 | docs/evidence/P04/ |

## 尚未完成或未驗證

- 尚未完成真人試玩紀錄與操作錄影；自動瀏覽器檢查已完成，但不能代替真人體驗。
- 尚未確認所有音效在 Safari／iOS 的 fallback；目前使用 OGG 候選與 Web Audio 視覺狀態並行。
- P04 已標記待驗收；真人試玩、錄影與 Safari／iOS OGG 實測仍是正式驗收前的明確缺項。
- P05 的其餘 12 關、P06 完整素材與 P07 複盤仍未開始。

## 下一位執行者

下一位執行者先讀本報告與 `docs/evidence/P04/`，再補真人桌機／手機試玩、操作錄影與 Safari／iOS 音效確認；不要把這些缺項改寫成已完成。後續階段入口仍是本檔案，下一階段為 P05 全 13 關。
