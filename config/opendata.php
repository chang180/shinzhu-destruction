<?php

use App\Services\OpenData\Adapters\LandUseAdapter;
use App\Services\OpenData\Adapters\ReservoirConditionsAdapter;
use App\Services\OpenData\Adapters\ScienceParkWaterAdapter;

return [

    /*
    |--------------------------------------------------------------------------
    | 正規化快照契約版本
    |--------------------------------------------------------------------------
    |
    | 欄位定義見 docs/DATA-CONTRACT.md。改變任何 metrics 鍵名、單位或品質判定
    | 門檻時必須同步升版，並更新契約文件與 fixture。
    |
    */

    'schema_version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | 抓取行為
    |--------------------------------------------------------------------------
    |
    | UA 固定且可設定；上游若要求識別可在 .env 覆寫。TLS 驗證永遠開啟。
    |
    */

    'user_agent' => env(
        'OPENDATA_USER_AGENT',
        'ShinzhuDestructionAcademy/0.2 (+https://github.com/chang180/shinzhu-destruction)'
    ),

    'demo_fixture_path' => database_path('fixtures/opendata'),

    /*
    |--------------------------------------------------------------------------
    | 資料來源
    |--------------------------------------------------------------------------
    |
    | resource_url 於 2026-09-08 由 data.gov.tw 資料集 API
    | (https://data.gov.tw/api/v2/rest/dataset/{id}) 的 distribution 欄位取得，
    | 不是從資料集 HTML 頁面猜測。
    |
    */

    'sources' => [

        'wra.reservoir_conditions' => [
            'adapter' => ReservoirConditionsAdapter::class,
            'label' => '水庫水情資料',
            'authority' => '經濟部水利署',
            'dataset_page' => 'https://data.gov.tw/dataset/45501',
            'resource_url' => 'https://opendata.wra.gov.tw/api/v2/2be9044c-6e44-4856-aad5-dd108c2e6679?sort=_importdate%20asc&format=JSON',
            'format' => 'json',
            'accept' => 'application/json',
            'allowed_hosts' => ['opendata.wra.gov.tw'],
            'expected_content_types' => ['application/json', 'text/json'],
            'max_bytes' => 8 * 1024 * 1024,
            'connect_timeout' => 5,
            'request_timeout' => 20,
            'budget_seconds' => 60,
            'max_attempts' => 3,
            'retry_delay_ms' => 1000,
            'schedule' => 'hourly',

            // 上游宣告每小時更新；48 小時未觀測即標 stale（TECHNICAL-SPEC §4.2）。
            'stale_after_hours' => 48,

            // 快照觀測視窗：只取每座水庫最近 N 小時的觀測列。
            'observation_window_hours' => 24,

            // 計算相對指標所需的最少觀測筆數，不足則 storage_index 為 null。
            'minimum_samples' => 6,

            /*
             * 水庫代碼對照表。
             *
             * 來源：2026-09-07 可行性盤點人工核對（api-probe/DATA-SOURCES-FEASIBILITY.md）。
             * 45501 payload 本身不含水庫名稱；上游 notes 指出名稱需對應「水庫每日營運狀況」
             * 資料集的 ReservoirName 欄位，本階段未機器核對該資料集，見 docs/DATA-CONTRACT.md
             * 的未驗證項目。
             *
             * relation 區分「位於新竹縣」與「供水涵蓋新竹地區」，不得把兩座都寫成新竹縣水庫。
             */
            'reservoirs' => [
                '10405' => [
                    'name' => '寶山第二水庫',
                    'county' => '新竹縣',
                    'relation' => 'located_in_hsinchu_county',
                ],
                '10501' => [
                    'name' => '永和山水庫',
                    'county' => '苗栗縣',
                    'relation' => 'supplies_hsinchu_area',
                ],
            ],
        ],

        'nstc.science_park_water' => [
            'adapter' => ScienceParkWaterAdapter::class,
            'label' => '新竹科學園區管理局用水量統計',
            'authority' => '國家科學及技術委員會新竹科學園區管理局',
            'dataset_page' => 'https://data.gov.tw/dataset/41280',
            'resource_url' => 'https://mas.nstc.gov.tw/OPENDATA/GetFile?format=csv&serialno=381&fileodr=2',
            'format' => 'csv',
            'accept' => 'text/csv, application/csv',
            'allowed_hosts' => ['mas.nstc.gov.tw'],
            'expected_content_types' => ['text/csv', 'application/csv', 'text/plain', 'application/octet-stream'],
            'max_bytes' => 4 * 1024 * 1024,
            'connect_timeout' => 5,
            'request_timeout' => 20,
            'budget_seconds' => 60,
            'max_attempts' => 3,
            'retry_delay_ms' => 1000,
            'schedule' => 'daily',

            // 上游 updateFrequency 為「不定期」，實際內容是整年度月報，
            // 因此以資料涵蓋的民國年度判斷新鮮度，不用抓取時間。
            'stale_after_roc_years' => 1,

            'minimum_samples' => 6,

            /*
             * 園區行政區歸屬。竹南園區位於苗栗縣，不能當成新竹行政區使用。
             */
            'parks' => [
                '新竹園區' => ['county' => '新竹市／新竹縣', 'in_hsinchu' => true],
                '生醫園區' => ['county' => '新竹縣', 'in_hsinchu' => true],
                '竹南園區' => ['county' => '苗栗縣', 'in_hsinchu' => false],
                '龍潭園區' => ['county' => '桃園市', 'in_hsinchu' => false],
                '銅鑼園區' => ['county' => '苗栗縣', 'in_hsinchu' => false],
                '宜蘭園區' => ['county' => '宜蘭縣', 'in_hsinchu' => false],
            ],
        ],

        'moi.land_use' => [
            'adapter' => LandUseAdapter::class,
            'label' => '113-114 年國土利用現況調查成果鄉鎮市區統計（3 級分類）',
            'authority' => '內政部國土測繪中心',
            'dataset_page' => 'https://data.gov.tw/dataset/178038',
            'resource_url' => 'https://opdadm.moi.gov.tw/api/v1/no-auth/resource/api/dataset/334BB88C-6861-48CE-A028-8B6392CBBC79/resource/E334C46A-B7B1-4CC4-AF29-942A185A14DB/download',
            'format' => 'csv',
            'accept' => 'text/csv',
            'allowed_hosts' => ['opdadm.moi.gov.tw'],
            'expected_content_types' => ['text/csv', 'application/csv', 'text/plain', 'application/octet-stream'],
            'max_bytes' => 16 * 1024 * 1024,
            'connect_timeout' => 5,
            'request_timeout' => 30,
            'budget_seconds' => 60,
            'max_attempts' => 3,
            'retry_delay_ms' => 1000,
            'schedule' => 'weekly',

            /*
             * 調查期間來自資料集標題（113-114 年），CSV 內沒有年度欄位，
             * 因此寫在設定並標明出處，不從資料列推測。
             */
            'survey_label' => '113-114年',
            'survey_end_roc_year' => 114,
            'stale_after_roc_years' => 2,

            'county' => '新竹縣',
            'expected_township_count' => 13,

            /*
             * 分類群組使用欄位標題內的「3 級分類代碼」前綴，互斥且不重疊；
             * 分母是同一列所有分類欄位的加總，不另外引用外部面積。
             */
            'category_groups' => [
                'agriculture' => ['0101', '0102', '0103', '0104'],
                'forest' => ['0201', '0202', '0203', '0204', '0205', '0206', '0207'],
                'built_up' => ['0501', '0502', '0503', '0504', '0505', '0506', '0507', '0508'],
                'park_green' => ['0702'],
            ],
        ],
    ],
];
