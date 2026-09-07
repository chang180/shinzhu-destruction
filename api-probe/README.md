# Hostinger → 新竹縣資料平台：主機端連線實驗

用途：先把最小 Node.js 服務部署到實際 Hostinger 主機，確認該主機能取得目標資料，再開發資料庫與完整遊戲後端。本工具沒有資料庫依賴，不會改動或取代 GitHub Pages 的 `/docs` 遊戲。

## 在 Hostinger 部署

1. 在 hPanel 新增 **Node.js Web App / Deploy Web App**，匯入本 repo 的 `main` 分支。
2. 專案根目錄選 repo 根目錄（`/` 或 `.`，依欄位顯示），**不是 `docs` 或 `api-probe`**。
3. 選 Node.js **22 或 24**，Framework 選 **Other**。此服務沒有外部套件。
4. Build command：`npm run build`；Entry file：`server.js`；若有 Start command 欄位填 `npm start`。Output directory 若必填，填 `.`，因本案不生成 dist，入口就在專案根目錄。不同 hPanel 版本可能顯示不同欄位。
5. 建議設定環境變數 `DEPLOYMENT_LABEL=Hostinger-你的機房地區`。可設定 `PROBE_TOKEN=自己產生的測試密碼`，部署後在測試頁輸入。請勿把密碼提交到 Git。
6. `PORT` 由主機提供即可，程式監聽 `0.0.0.0`；未提供時使用 3000。
7. 部署後打開網站根網址，確認顯示 Node.js 主機資訊，按「依序測試全部預設」，下載結果 JSON。

Hostinger 官方文件列出 Business／Cloud 的受管 Node.js Web App，以及 Other 類型；VPS 可自行執行 Node。一般純靜態／PHP 上傳流程不會啟動此服務。設定以帳號實際提供的方案與欄位為準。

官方部署文件（2026-09-07 查閱）：https://www.hostinger.com/support/how-to-deploy-a-nodejs-website-in-hostinger/

## 本機對照

```sh
npm ci
npm test
npm start
```

開啟 `http://localhost:3000`，執行相同目標、相同 IP 模式，下載本機結果。再與 Hostinger 的結果比對。

每個測試最多約 20 秒、回應內容上限 1 MiB；超過上限不會宣稱 JSON 驗證通過。每個程序同時只允許一項測試，間隔至少 1 秒，沒有自動重試或壓力測試。重新部署與多程序各有自己的限制；這是短期診斷工具。

## 預設目標與來源

| 目標 | 用途 |
|---|---|
| `https://dip.hsinchu.gov.tw/` | 數據平台首頁，驗證 DNS／TLS／HTTP |
| `https://dip.hsinchu.gov.tw/Home/NewsHomeList` | 平台首頁實際使用的 GET 資料介面，測直接呼叫 |
| 同上，先取得首頁工作階段 | 依公開首頁的 `AntiforgeryField`、Cookie、`X-XSRF-TOKEN` 進行正常請求；不繞過登入或權限 |
| `county-json`（見 targets.js） | `ws.hsinchu.gov.tw` 的「新竹縣鄉鎮市公所」JSON 靜態資源 |
| `https://www.hsinchu.gov.tw/OpenData` | 縣府開放資料專區，作為另一個主機對照 |

JSON 資源來源：https://data.gov.tw/dataset/162710 。數據平台介面由公開首頁程式讀取，**不是已確認有長期相容承諾的正式 OpenAPI**。公所 JSON 是檔案，不是動態查詢 API；兩者成功意義分開。自訂公開 GET 網址限三個已列縣府主機，預期回傳 JSON。其他主機或 POST 型介面須依實際文件另外新增，不可用這次結果代替。

## 報告包含

- 後端時間、Node 版本、執行系統、程序識別及自訂環境名稱。
- 每個重新導向步驟、DNS 位址、實際遠端 IP、DNS／TCP／TLS／首位元組／總時間。時間以該步驟起點計算；TCP／TLS 為階段時間，首位元組與總時間為累計值。
- HTTP 狀態、部分回應標頭、JSON 是否可解析、陣列筆數、物件第一層欄位、最多 3,000 字元內容片段。
- 逾時、DNS、TLS、連線與工作階段錯誤。HTTP 200 但回 HTML 不會判成 JSON 成功。

`family=0` 採系統 DNS 順序的第一個公開位址，不會偷偷切到另一個 IP 重試。IPv4／IPv6 模式可協助分辨路由差異；IPv6 沒有 DNS 記錄或主機未支援 IPv6 不等於海外封鎖。

報告不含環境變數清單、API 密碼、Cookie 或工作階段 token。內容片段來自公開來源，以純文字顯示。只讀取最多 1 MiB，不提供任意檔案存取或通用代理。TLS 憑證檢查保持啟用；每一跳都檢查主機白名單並固定已解析的公開 IP。

## 判讀限制

後端請求不受瀏覽器 CORS 限制。本機成功不等於 Hostinger 成功；HTML 200 不等於 API 成功；收到 JSON 也可能是業務錯誤訊息。403、429 或逾時都不能單獨證明地區封鎖。下載本機及 Hostinger 同目標結果，搭配機房地區與測試時間才有比較依據。程式不推斷所在地，也不使用第三方 IP 地理定位服務。

本次只驗證出站連線與公開資料取得，沒有驗證資料庫、排程、正式 API 授權或長期 SLA。

## API

- `GET /api/health`：主機狀態。
- `GET /api/targets`：預設項目。
- `POST /api/probe`：例如 `{"targetId":"dip-news-session","family":4}`；自訂則 `{"url":"https://dip.hsinchu.gov.tw/實際公開介面","family":0}`。
- 設定 `PROBE_TOKEN` 時，POST 必須帶 `X-Probe-Token`。
- HTTP 200 表示測試報告已產生，**上游測試結果看 `outcome` 與 `hops`，不是只看本工具的 HTTP 200**。

AI 共創：GPT-6 Astra（OpenAI Codex），包含程式、測試及文件。
