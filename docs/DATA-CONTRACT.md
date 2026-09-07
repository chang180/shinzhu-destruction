# 資料契約：正規化快照

版本 `schema_version = 1.0.0`｜建立：2026-09-08（P02）｜實作：`config/opendata.php`、`app/Services/OpenData/`

這份文件是遊戲端唯一可以依賴的資料形狀。欄位、單位或品質門檻改變時必須升版，並同步更新 fixture 與測試。數值定義以本文件為準；遊戲數值待 P03 的 `BALANCE.md` 固定。

**本契約描述的是遊戲情境輸入，不是災害預測。** 任何 metric 都不得在 UI 上呈現為官方風險評估、環境測量或政策成效。

## 1. 三個來源

resource_url 於 2026-09-08 由 `https://data.gov.tw/api/v2/rest/dataset/{id}` 的 `distribution` 欄位取得，不是從資料集 HTML 頁面猜測，也沒有自創查詢參數。

| source_id | 資料集 | 提供機關 | 主機 | 格式 | 抓取頻率 |
|---|---|---|---|---|---|
| `wra.reservoir_conditions` | [45501 水庫水情資料](https://data.gov.tw/dataset/45501) | 經濟部水利署 | `opendata.wra.gov.tw` | JSON | 每小時 |
| `nstc.science_park_water` | [41280 新竹科學園區管理局用水量統計](https://data.gov.tw/dataset/41280) | 國科會新竹科學園區管理局 | `mas.nstc.gov.tw` | CSV | 每日檢查 |
| `moi.land_use` | [178038 113-114 年國土利用現況調查成果鄉鎮市區統計（3 級分類）](https://data.gov.tw/dataset/178038) | 內政部國土測繪中心 | `opdadm.moi.gov.tw` | CSV | 每週檢查 |

「抓取頻率」只代表檢查上游是否換版。園區用水與國土利用實際上是年度統計，不會因為每天抓取就變成每日資料。

## 2. 快照結構

```jsonc
{
  "snapshot_id": "01a07cfe-8bf1-7373-ab8a-8a2197fd2561", // UUIDv7；示範情境為固定字串
  "source_id": "wra.reservoir_conditions",
  "schema_version": "1.0.0",
  "resource_url": "https://opendata.wra.gov.tw/api/v2/...",
  "content_hash": "sha256 of raw bytes",
  "fetched_at": "2026-09-07T17:50:51+00:00",   // 一律 UTC
  "observed_at": "2026-09-07T17:00:00+00:00",  // 觀測型來源才有；統計型為 null
  "period": null,                               // 統計型來源才有；觀測型為 null
  "quality": "fresh|stale|demo|unavailable",
  "quality_reason": "可讀原因，寫進來源狀態面板",
  "scope": { },
  "metrics": { },
  "units": { },
  "warnings": [{ "code": "...", "message": "...", "context": { } }],
  "byte_size": 282788
}
```

`period` 的形狀是 `{start, end, label, calendar}`；`calendar` 標示 `roc`（民國）或 `demo`，`start`／`end` 一律換算成西元並存 UTC。UI 顯示採 Asia/Taipei。

### 品質狀態

| quality | 意義 | 誰產生 |
|---|---|---|
| `fresh` | 上游資料，觀測時間或涵蓋期間在門檻內 | adapter |
| `stale` | 上游資料，解析成功但期間超過門檻。**數值不變**，只換標示 | adapter |
| `demo` | 版本化示範情境，人工設定的中性值 | `database/fixtures/opendata/` |
| `unavailable` | 沒有任何可用快照 | 來源狀態報告 |

UI 必須逐來源顯示品質。一個來源新鮮不代表整局可以標成「即時」。

### 門檻

| source_id | 判定依據 | 門檻 |
|---|---|---|
| `wra.reservoir_conditions` | 最新觀測時間與抓取時間的差 | > 48 小時即 `stale` |
| `nstc.science_park_water` | payload 內的民國年度 vs 抓取當下民國年 | 落後 > 1 年即 `stale` |
| `moi.land_use` | 設定內的調查結束民國年 vs 抓取當下民國年 | 落後 > 2 年即 `stale` |

年度統計不套用 48 小時門檻；把正常的年報當成壞資料是錯的。

## 3. 各來源的 metrics

### 3.1 `wra.reservoir_conditions`

`metrics` 以水庫代碼為鍵。追蹤兩座：

| 代碼 | 名稱 | county | relation |
|---|---|---|---|
| `10405` | 寶山第二水庫 | 新竹縣 | `located_in_hsinchu_county` |
| `10501` | 永和山水庫 | 苗栗縣 | `supplies_hsinchu_area` |

`relation` 存在的理由是：**這兩座水庫不都在新竹縣**，永和山水庫位於苗栗縣，只是供水範圍涵蓋新竹地區。文案不得寫成「新竹縣的兩座水庫」。

| metric | 單位 | 說明 |
|---|---|---|
| `observed_at` | ISO8601 UTC | 該水庫視窗內最新一筆觀測 |
| `sample_count` | 筆 | 視窗內有效蓄水量的筆數 |
| `effective_storage_latest` | 萬立方公尺 | 最新有效蓄水量 |
| `effective_storage_median` | 萬立方公尺 | 視窗內中位數 |
| `storage_index` | 比值 | `latest ÷ median`，同一水庫、同一欄位自我比較 |
| `water_level_latest` | 公尺 | |
| `catchment_rainfall_latest` | 毫米 | 集水區累積降雨量 |
| `total_outflow_latest` | 立方公尺／秒 | |

觀測視窗為最新觀測往前 24 小時。`storage_index` 需要至少 6 筆有效樣本，否則為 `null`，並記 `insufficient_samples` 警告——不得填 `1.0` 假裝有資料。

**不提供蓄水百分比。** 這份資源的 payload 只有 `effectivewaterstoragecapacity`，沒有滿水位容量欄位，也沒有上游說明提到的「蓄水百分比」。每次正規化都會附上 `storage_percentage_unavailable` 警告。資料集頁面另外要求不要用綠／黃／橙／紅表達蓄水率，原始資料面板一律使用中性色與數值，與虛構戰鬥血條分開。

單位標示為「萬立方公尺」的依據是水利署資料集說明，payload 本身沒有標單位；`units` 已註明這點。

### 3.2 `nstc.science_park_water`

`metrics` 以園區名稱為鍵，涵蓋 CSV 內全部六個園區。

| metric | 單位 | 說明 |
|---|---|---|
| `county` / `in_hsinchu` | — | 行政區歸屬，來自設定 |
| `roc_year` | 民國年 | 由 payload 的「年度」欄解析 |
| `monthly` | CMD | 1～12 月，缺值為 `null` |
| `valid_months` | 月 | 非缺值月份數 |
| `latest_month` / `latest_value` | 月 / CMD | 最後一個有值的月份 |
| `median_value` | CMD | 有效月份中位數 |
| `demand_index` | 比值 | `latest ÷ median`，需至少 6 個有效月份 |

`in_hsinchu` 為 `true` 者只有新竹園區與生醫園區。竹南園區在苗栗縣、龍潭園區在桃園市、銅鑼園區在苗栗縣、宜蘭園區在宜蘭縣，**不得當成新竹行政區的用水量**。整份資料也不是新竹縣全縣即時用水。

### 3.3 `moi.land_use`

`metrics` 以鄉鎮市名稱為鍵，涵蓋新竹縣 13 個鄉鎮市，另有 `_county` 彙總列。

| metric | 單位 | 說明 |
|---|---|---|
| `total_area` | 公頃 | 同一列全部互斥分類面積加總 |
| `agriculture_area` / `forest_area` / `built_up_area` / `park_green_area` | 公頃 | 依 3 級分類代碼前綴分組 |
| `*_ratio` | 比例 | 對應面積 ÷ 同列 `total_area` |
| `missing_category_cells` | 欄 | 缺值欄位數，未計入分母 |

分組使用欄位標題內的 6 碼分類代碼：農業 `0101`–`0104`、森林 `0201`–`0207`、建築使用 `0501`–`0508`、公園綠地廣場 `0702`。這些群組互斥，不重複計算。

**分母是調查自己的總面積，不是官方行政區面積。** 2026-09-08 的實際資料中，新竹縣 13 鄉鎮市加總為 141,152.26 公頃；這個數字與一般引用的新竹縣公告面積（約 1,427 平方公里）有約 1% 的差距，本階段沒有向內政部核對差異來源。因此 `*_ratio` 是「調查涵蓋面積內的占比」，不能說成「全縣土地的百分之幾」。

CSV 只有縣市／鄉鎮市名稱，**沒有行政區代碼**；`scope.administrative_codes_available` 固定為 `false`。

公園綠地廣場面積是綠地配置的代理指標，每次正規化都附 `not_a_heat_measurement` 警告：這不是熱島強度或降溫效果的量測值。

## 4. 缺值、零值與失敗

- `0` 是有效值。空字串、`-`、`--`、`N/A` 轉成 `null` 並記錄警告，**不以 0 代入**。
- 解析不出數字的儲存格同樣視為缺值，不猜測。
- 缺值不進入分母、不進入中位數樣本。

失敗以 `reason_code` 分類，寫進日誌與來源狀態，不把上游完整回應或內部路徑送到前端：

| reason_code | 觸發條件 |
|---|---|
| `transport_timeout` | 連線或讀取逾時 |
| `http_status_{code}` | 上游回 4xx／5xx。429、5xx 有限重試；403、404 直接失敗 |
| `payload_too_large` | 串流讀取超過該來源的位元組上限 |
| `unexpected_content_type` | Content-Type 不在該來源的允許清單 |
| `html_masquerade` | HTTP 2xx 但內容以 `<` 開頭（HTML／腳本假成功） |
| `redirect_not_allowed` | 重新導向到白名單以外的主機 |
| `payload_unparsable` | JSON／CSV 無法解析，或沒有資料列 |
| `missing_required_fields` | 缺少必要欄位、找不到目標行政區或水庫 |
| `budget_exhausted` | 單來源總工作預算用盡 |

**任何失敗都不會覆蓋既有快照。** 抓取與清洗都在交易外完成，只有通過驗證的正規化結果才進交易；發布時在同一交易內把同來源舊列的 `is_current` 關掉再插入新列。

## 5. 傳輸防護

| 項目 | 設定 |
|---|---|
| User-Agent | 固定值，可用 `OPENDATA_USER_AGENT` 覆寫 |
| TLS 驗證 | 永遠開啟，不提供關閉選項 |
| 連線逾時 | 5 秒 |
| 讀取逾時 | 20–30 秒（依來源） |
| 單來源總預算 | 60 秒，包含重試 |
| 重試 | 最多 3 次嘗試，只對 429 與 5xx／連線失敗重試 |
| 位元組上限 | 水庫 8 MiB、園區 4 MiB、國土 16 MiB，串流讀取即時中止 |
| 重新導向 | 最多 3 次，只允許 https 與該來源白名單主機 |

## 6. 備援

`SnapshotResolver` 開局時解析一組快照：**目前有效快照（`fresh` 或 `stale` 都算）→ 版本化示範情境 fixture**。

示範情境放在 `database/fixtures/opendata/`，每個來源一份，`quality` 固定為 `demo`，`scope.fixture_version` 標示版本。數值是人工設定的中性值（所有指標 = 1.0 或等值月份），**不是任何一次真實觀測的複製**。`FixtureRepository` 會拒絕載入 `quality` 不是 `demo` 的檔案。

`SnapshotResolver::containsDemoData()` 供 UI 判斷是否顯示「示範情境」提示。

一場對局開始後綁定 `snapshot_id` 並凍結，之後不再重新解析，也不在回合中下載上游資料。既有對局即使快照被新版取代，仍讀原本的 `snapshot_id`。

## 7. 儲存

`data_snapshots` 只存正規化結果，不存整份上游檔案。2026-09-08 實測：

| source_id | 上游原始大小 | 儲存的 payload |
|---|---|---|
| `wra.reservoir_conditions` | 282,788 bytes | 2,266 bytes |
| `nstc.science_park_water` | 818 bytes | 3,290 bytes |
| `moi.land_use` | 155,896 bytes | 5,689 bytes |

`SnapshotRepository::prune()` 可依來源保留最近 N 筆，但預設不執行。**P03 加入 `runs` 之後，必須先排除仍被對局引用的 `snapshot_id` 才能啟用定期清理**，否則會破壞重播。

## 8. 排程

`routes/console.php` 定義三條排程，全部走 `withoutOverlapping`，Hostinger 只需要一條 cron 呼叫 `php artisan schedule:run`，不需要常駐 worker 或 Redis。目前三條排程各自對應一個來源，因此鎖等同逐來源；若之後把多個來源併進同一頻率，必須改成逐來源鎖：

```text
0  *  * * *   opendata:refresh --schedule=hourly
10 4  * * *   opendata:refresh --schedule=daily
40 4  * * 1   opendata:refresh --schedule=weekly
```

手動操作：

```bash
php artisan opendata:refresh
```

```bash
php artisan opendata:status
```

## 9. 已知限制與未驗證項目

1. **水庫名稱對照未機器驗證。** 45501 的 payload 沒有水庫名稱；上游說明指出名稱要對應「水庫每日營運狀況」資料集的 `ReservoirName` 欄位。目前的代碼對照來自 2026-09-07 的人工核對，P02 沒有抓取該資料集比對。
2. **蓄水率不可得。** 見 §3.1。若之後要顯示蓄水百分比，必須先找到同一水庫、同一口徑的容量來源，不能用現有欄位推算。
3. **`mas.nstc.gov.tw` 的 UA 行為與舊報告不同。** 2026-09-07 的盤點記載預設 curl UA 會被擋 403；2026-09-08 從本機以 `curl/8.8.0` 與專案 UA 實測**都是 200**。因此不能宣稱「一定要帶瀏覽器 UA」，但仍固定送出可識別 UA，並保留 403 的受控失敗處理。
4. **正式主機解析樣本未取得。** 本階段全部在本機執行；Hostinger PHP 對三個部會主機的實際解析結果仍待 P09 前補齊。
5. **情境修正公式尚未定義。** 本契約只到正規化指標為止；`storage_index`、`demand_index`、`*_ratio` 如何換算成每系 −15%～+15% 的遊戲修正，由 P03 的 `BALANCE.md` 固定。
6. **`data.gov.tw` 資源 URL 可能變動。** 目前硬寫在 `config/opendata.php`。若上游改版，先重新查詢資料集 API 的 `distribution`，更新設定與本文件，不要在程式裡拼湊網址。
