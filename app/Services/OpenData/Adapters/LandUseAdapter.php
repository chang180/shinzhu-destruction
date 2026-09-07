<?php

namespace App\Services\OpenData\Adapters;

use App\Services\OpenData\Exceptions\DataSourceException;
use App\Services\OpenData\Snapshot\NormalizedSnapshot;
use App\Services\OpenData\Snapshot\SnapshotPeriod;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use App\Services\OpenData\Support\CsvTable;
use App\Services\OpenData\Support\Metrics;
use App\Services\OpenData\Support\RawPayload;
use Carbon\CarbonImmutable;

/**
 * 內政部國土測繪中心「國土利用現況調查成果鄉鎮市區統計」（data.gov.tw 178038）。
 *
 * CSV 只有縣市／鄉鎮市名稱，沒有行政區代碼，也沒有年度欄位；調查期間寫在
 * 設定並註明出處。比例的分母是同一列所有互斥分類面積的加總，不引用外部總面積。
 * 這是土地利用調查，不是熱島或綠覆率量測。
 */
class LandUseAdapter extends AbstractAdapter
{
    private const COUNTY_COLUMN = '縣市';

    private const TOWNSHIP_COLUMN = '鄉鎮市';

    private const UNIT_COLUMN = '面積單位';

    public function normalize(RawPayload $payload): NormalizedSnapshot
    {
        $table = CsvTable::parse($this->sourceId, $payload->bodyWithoutBom());
        $table->requireColumns($this->sourceId, [self::COUNTY_COLUMN, self::TOWNSHIP_COLUMN, self::UNIT_COLUMN]);

        $categoryColumns = $this->categoryColumns($table->header);

        if ($categoryColumns === []) {
            throw DataSourceException::missingRequiredFields($this->sourceId, '找不到任何土地利用分類欄位');
        }

        $county = (string) $this->source['county'];
        $rows = array_values(array_filter(
            $table->rows,
            static fn (array $row): bool => $row[self::COUNTY_COLUMN] === $county,
        ));

        if ($rows === []) {
            throw DataSourceException::missingRequiredFields(
                $this->sourceId,
                "CSV 內找不到 {$county} 的資料列",
                ['county' => $county],
            );
        }

        $warnings = [];
        $expected = (int) $this->source['expected_township_count'];

        if (count($rows) !== $expected) {
            $warnings[] = $this->warning(
                'township_count_mismatch',
                "{$county} 取得 ".count($rows)." 個鄉鎮市，與預期的 {$expected} 個不同",
                ['found' => count($rows), 'expected' => $expected],
            );
        }

        $unit = $rows[0][self::UNIT_COLUMN];
        $metrics = [];
        $countyTotals = ['total' => 0.0, 'agriculture' => 0.0, 'forest' => 0.0, 'built_up' => 0.0, 'park_green' => 0.0];

        foreach ($rows as $row) {
            $township = $row[self::TOWNSHIP_COLUMN];
            $areas = [];
            $total = 0.0;
            $missingColumns = 0;

            foreach ($categoryColumns as $column => $code) {
                $value = CsvTable::toFloat($row[$column] ?? null);

                if ($value === null) {
                    $missingColumns++;

                    continue;
                }

                $areas[$code] = $value;
                $total += $value;
            }

            if ($missingColumns > 0) {
                $warnings[] = $this->warning(
                    'missing_category_cells',
                    "{$township} 有 {$missingColumns} 個分類欄位缺值，未計入分母",
                    ['township' => $township, 'missing_columns' => $missingColumns],
                );
            }

            $groups = $this->groupAreas($areas);

            $metrics[$township] = [
                'total_area' => Metrics::round($total, 2),
                'agriculture_area' => Metrics::round($groups['agriculture'], 2),
                'forest_area' => Metrics::round($groups['forest'], 2),
                'built_up_area' => Metrics::round($groups['built_up'], 2),
                'park_green_area' => Metrics::round($groups['park_green'], 2),
                'agriculture_ratio' => Metrics::ratio($groups['agriculture'], $total),
                'forest_ratio' => Metrics::ratio($groups['forest'], $total),
                'built_up_ratio' => Metrics::ratio($groups['built_up'], $total),
                'park_green_ratio' => Metrics::ratio($groups['park_green'], $total),
                'missing_category_cells' => $missingColumns,
            ];

            $countyTotals['total'] += $total;

            foreach ($groups as $group => $area) {
                $countyTotals[$group] += $area;
            }
        }

        $metrics['_county'] = [
            'name' => $county,
            'township_count' => count($rows),
            'total_area' => Metrics::round($countyTotals['total'], 2),
            'agriculture_area' => Metrics::round($countyTotals['agriculture'], 2),
            'forest_area' => Metrics::round($countyTotals['forest'], 2),
            'built_up_area' => Metrics::round($countyTotals['built_up'], 2),
            'park_green_area' => Metrics::round($countyTotals['park_green'], 2),
            'agriculture_ratio' => Metrics::ratio($countyTotals['agriculture'], $countyTotals['total']),
            'forest_ratio' => Metrics::ratio($countyTotals['forest'], $countyTotals['total']),
            'built_up_ratio' => Metrics::ratio($countyTotals['built_up'], $countyTotals['total']),
            'park_green_ratio' => Metrics::ratio($countyTotals['park_green'], $countyTotals['total']),
        ];

        $warnings[] = $this->warning(
            'not_a_heat_measurement',
            '公園綠地廣場面積是綠地配置的代理指標，不是熱島或降溫效果量測值',
        );

        $surveyEndRocYear = (int) $this->source['survey_end_roc_year'];
        [$quality, $reason] = $this->assessQuality($surveyEndRocYear, $payload->fetchedAt);

        return new NormalizedSnapshot(
            snapshotId: NormalizedSnapshot::newId(),
            sourceId: $this->sourceId,
            schemaVersion: $this->schemaVersion,
            resourceUrl: $payload->resourceUrl,
            contentHash: $payload->contentHash,
            fetchedAt: $payload->fetchedAt,
            observedAt: null,
            period: $this->period($surveyEndRocYear),
            quality: $quality,
            qualityReason: $reason,
            scope: [
                'kind' => 'township',
                'authority' => $this->source['authority'],
                'dataset_page' => $this->source['dataset_page'],
                'county' => $county,
                'township_count' => count($rows),
                'survey_label' => (string) $this->source['survey_label'],
                'classification' => '108 年版土地利用分級分類系統表，3 級分類',
                'administrative_codes_available' => false,
            ],
            metrics: $metrics,
            units: [
                'total_area' => $unit,
                'agriculture_area' => $unit,
                'forest_area' => $unit,
                'built_up_area' => $unit,
                'park_green_area' => $unit,
                'agriculture_ratio' => '比例（同列互斥分類面積 ÷ 同列總面積）',
                'forest_ratio' => '比例（同上）',
                'built_up_ratio' => '比例（同上）',
                'park_green_ratio' => '比例（同上）',
            ],
            warnings: $warnings,
            byteSize: $payload->byteSize,
        );
    }

    /**
     * @param  list<string>  $header
     * @return array<string, string> 欄位名稱 => 6 碼分類代碼
     */
    private function categoryColumns(array $header): array
    {
        $columns = [];

        foreach ($header as $column) {
            if (preg_match('/(\d{6})/', $column, $matches) === 1) {
                $columns[$column] = $matches[1];
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, float>  $areas  分類代碼 => 面積
     * @return array{agriculture: float, forest: float, built_up: float, park_green: float}
     */
    private function groupAreas(array $areas): array
    {
        /** @var array<string, list<string>> $groups */
        $groups = $this->source['category_groups'];
        $totals = ['agriculture' => 0.0, 'forest' => 0.0, 'built_up' => 0.0, 'park_green' => 0.0];

        foreach ($areas as $code => $area) {
            foreach ($groups as $group => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($code, $prefix)) {
                        $totals[$group] += $area;

                        continue 3;
                    }
                }
            }
        }

        return $totals;
    }

    private function period(int $surveyEndRocYear): SnapshotPeriod
    {
        preg_match('/^(\d{2,3})/', (string) $this->source['survey_label'], $matches);
        $startRocYear = isset($matches[1]) ? (int) $matches[1] : $surveyEndRocYear;

        return new SnapshotPeriod(
            CarbonImmutable::create($startRocYear + 1911, 1, 1, 0, 0, 0, 'Asia/Taipei')->utc(),
            CarbonImmutable::create($surveyEndRocYear + 1911, 12, 31, 23, 59, 59, 'Asia/Taipei')->utc(),
            (string) $this->source['survey_label'],
            'roc',
        );
    }

    /**
     * @return array{0: SnapshotQuality, 1: string}
     */
    private function assessQuality(int $surveyEndRocYear, CarbonImmutable $fetchedAt): array
    {
        $currentRocYear = $fetchedAt->setTimezone('Asia/Taipei')->year - 1911;
        $allowedLag = (int) $this->source['stale_after_roc_years'];

        if ($currentRocYear - $surveyEndRocYear > $allowedLag) {
            return [SnapshotQuality::Stale, "調查期間止於民國 {$surveyEndRocYear} 年，落後超過 {$allowedLag} 年"];
        }

        return [SnapshotQuality::Fresh, "調查期間止於民國 {$surveyEndRocYear} 年，在允許落後 {$allowedLag} 年內"];
    }
}
