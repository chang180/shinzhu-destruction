---
paths:
  - 'app/Services/OpenData/**'
---

# Open Data

## 開放資料 adapter 的真實資料界線
正規化欄位、單位與品質門檻以 docs/DATA-CONTRACT.md 為準，改欄位要升 schema_version 並更新 database/fixtures/opendata 與測試。

不可推算上游沒有的值：45501 沒有滿水位容量，因此不輸出蓄水率，只用同水庫自我比較的 storage_index。缺值（空字串／--／N/A）轉 null 並記警告，0 是有效值，缺值不進分母也不進中位數樣本。

resource_url 一律寫在 config/opendata.php，來自 data.gov.tw 資料集 API 的 distribution，程式不拼湊網址、不自創縣市篩選參數。

抓取失敗永遠不覆蓋既有快照：fetch/normalize 在交易外，只有驗證過的結果才進交易發布。上游可能回 HTTP 200 但內容是 HTML/script（例如 mas.nstc.gov.tw 找不到檔案時），必須當失敗處理。
