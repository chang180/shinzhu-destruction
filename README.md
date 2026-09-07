# 我的反派學院：超認真毀滅新竹計畫

世外高人｜2026 新竹縣智慧沙盒創新計畫參賽原型。

## 靜態遊戲 MVP

12 回合內將虛構城市韌性降到零。三系普通技能造成少量傷害、累積印記；三系各滿三枚後終招可通關。連續三種不同技能觸發共鳴，城市每三回合修復。包含勝負戰報、永續解說、提示、重玩、鍵盤操作與行動版配置。

所有數值為遊戲平衡設定，非官方風險、真實環境預測或政策成效。本版未串接即時資料或 AI；教授解說採固定文本。來源連結列在頁面底部，作為後續資料整合方向。

介面為純 CSS／原生 JS 呈現，無圖片素材與框架依賴：核心區塊為 HP 環形進度與粒子軌道、技能卡依水／熱／土地三系配色、終招印記滿載時有動態光效，深色風格搭配漸層背景與玻璃感面板。

## GitHub Pages

發布來源為 `main` 分支的 `/docs`。網站入口： https://chang180.github.io/shinzhu-destruction/

無需安裝或建置，HTML、CSS、JavaScript 直接由 Pages 提供。中文字型為可選的 Google Fonts，無法載入時使用系統字型。

## 驗證

`node --test tests/game.test.cjs`

覆蓋可通關路線、亂放終招失敗、印記消耗、連攜與修復、10,000 場固定種子的隨機策略。這是遊戲規則測試，不等同真人使用者測試。

## Hostinger 主機端 API 連線實驗

repo 根目錄新增可獨立啟動的 Node.js 服務，用於驗證 Hostinger 主機能否取得新竹縣公開資料。使用 `npm start`，部署入口 `server.js`；完整步驟與結果判讀見 [api-probe/README.md](api-probe/README.md)。GitHub Pages 仍只發布 `docs/` 遊戲。

**實測結論（2026-09-07，見 [api-probe/HOSTINGER-RESULT.md](api-probe/HOSTINGER-RESULT.md)）：** 新竹縣政府自有機房（`dip.hsinchu.gov.tw`、`ws.hsinchu.gov.tw`、`www.hsinchu.gov.tw`）從 Hostinger 主機連線逾時，型態指向該機房對海外來源 IP 有邊界限制；同一台主機呼叫中央氣象署開放資料平台（`opendata.cwa.gov.tw`）則連線正常，證明並非 Hostinger 出站被擋，也不是串接方式的問題，差異在目的地網路。

進一步盤點後（見 [api-probe/DATA-SOURCES-FEASIBILITY.md](api-probe/DATA-SOURCES-FEASIBILITY.md)），確認遊戲頁面原本列出的三個 `data.gov.tw` 資料集，其實際資源都掛在中央部會自己的開放資料平台（經濟部水利署、內政部國土測繪中心、國科會新竹科學園區管理局），而非新竹縣機房，從 Hostinger 全部可連、可用，且內容確實涵蓋新竹縣（水庫水情含寶山第二水庫、永和山水庫；國土利用含新竹縣 13 個鄉鎮市）。後續資料串接應走這些部會平台，避免直接呼叫新竹縣自有機房。

## AI 使用與署名

AI 共創：**GPT-6 Astra（透過 OpenAI Codex）**。本靜態原型的遊戲規則草案、核心程式、介面、固定文案與測試由 GPT-6 Astra 協助生成；**Claude Sonnet 5（透過 Claude Code）** 協助完成 Hostinger 主機連線實測、`data.gov.tw` 資料來源可行性盤點，以及遊戲頁面（`docs/`）的視覺改版；張建文提供提案、創意方向與成果確認。參賽者需檢核並依競賽簡章如實揭露。規劃文件與含個人資料的報名附件不在本次公開發布範圍。
