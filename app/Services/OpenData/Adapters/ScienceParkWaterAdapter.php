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
 * 國科會新竹科學園區管理局「用水量統計」（data.gov.tw 41280）。
 *
 * 上游是寬表 CSV：一列一個園區，欄位為某個民國年度的 1～12 月用水量（CMD）。
 * 這不是新竹縣全縣即時用水，也不是逐日資料；竹南、龍潭、銅鑼、宜蘭園區
 * 不在新竹行政區內，metrics 逐園區標示 in_hsinchu。
 */
class ScienceParkWaterAdapter extends AbstractAdapter
{
    private const PARK_COLUMN = '園區名稱';

    private const YEAR_COLUMN = '年度';

    public function normalize(RawPayload $payload): NormalizedSnapshot
    {
        $table = CsvTable::parse($this->sourceId, $payload->bodyWithoutBom());
        $table->requireColumns($this->sourceId, [self::PARK_COLUMN, self::YEAR_COLUMN]);

        $monthColumns = $this->monthColumns($table->header);

        if (count($monthColumns) < 12) {
            throw DataSourceException::missingRequiredFields(
                $this->sourceId,
                '月份欄位不足 12 個',
                ['month_columns' => count($monthColumns)],
            );
        }

        $warnings = [];

        if ($table->skippedRows > 0) {
            $warnings[] = $this->warning(
                'malformed_rows_skipped',
                "有 {$table->skippedRows} 列的欄數與標題列不符，已略過",
                ['skipped_rows' => $table->skippedRows],
            );
        }

        $metrics = [];
        $rocYears = [];
        $unit = null;
        $minimumSamples = (int) $this->source['minimum_samples'];
        /** @var array<string, array{county: string, in_hsinchu: bool}> $parkMeta */
        $parkMeta = $this->source['parks'];

        foreach ($table->rows as $row) {
            $park = $row[self::PARK_COLUMN];

            if ($park === '') {
                continue;
            }

            $rocYear = $this->parseRocYear($row[self::YEAR_COLUMN]);
            $unit ??= $this->parseUnit($row[self::YEAR_COLUMN]);

            if ($rocYear === null) {
                $warnings[] = $this->warning(
                    'unparsable_year',
                    "園區 {$park} 的年度欄位無法解析，該列略過",
                    ['park' => $park],
                );

                continue;
            }

            $rocYears[] = $rocYear;

            $monthly = [];
            $samples = [];
            $latestMonth = null;
            $latestValue = null;

            foreach ($monthColumns as $month => $column) {
                $value = CsvTable::toFloat($row[$column] ?? null);
                $monthly[$month] = $value;

                if ($value === null) {
                    $warnings[] = $this->warning(
                        'missing_month',
                        "園區 {$park} {$rocYear} 年 {$month} 月缺值，不以 0 代入",
                        ['park' => $park, 'roc_year' => $rocYear, 'month' => $month],
                    );

                    continue;
                }

                $samples[] = $value;
                $latestMonth = $month;
                $latestValue = $value;
            }

            $demandIndex = Metrics::relativeIndex($latestValue, $samples, $minimumSamples);

            if ($demandIndex === null) {
                $warnings[] = $this->warning(
                    'insufficient_samples',
                    "園區 {$park} 有效月份少於 {$minimumSamples} 筆，不計算需求指標",
                    ['park' => $park, 'valid_months' => count($samples)],
                );
            }

            $meta = $parkMeta[$park] ?? null;

            if ($meta === null) {
                $warnings[] = $this->warning(
                    'unmapped_park',
                    "設定檔沒有園區 {$park} 的行政區歸屬，標為未知",
                    ['park' => $park],
                );
            }

            $metrics[$park] = [
                'county' => $meta['county'] ?? null,
                'in_hsinchu' => $meta['in_hsinchu'] ?? null,
                'roc_year' => $rocYear,
                'monthly' => $monthly,
                'valid_months' => count($samples),
                'latest_month' => $latestMonth,
                'latest_value' => Metrics::round($latestValue, 1),
                'median_value' => Metrics::median($samples),
                'demand_index' => $demandIndex,
            ];
        }

        if ($metrics === [] || $rocYears === []) {
            throw DataSourceException::payloadUnparsable($this->sourceId, '沒有任何可用的園區資料列');
        }

        $latestRocYear = max($rocYears);
        $period = $this->period($latestRocYear);
        [$quality, $reason] = $this->assessQuality($latestRocYear, $payload->fetchedAt);

        return new NormalizedSnapshot(
            snapshotId: NormalizedSnapshot::newId(),
            sourceId: $this->sourceId,
            schemaVersion: $this->schemaVersion,
            resourceUrl: $payload->resourceUrl,
            contentHash: $payload->contentHash,
            fetchedAt: $payload->fetchedAt,
            observedAt: null,
            period: $period,
            quality: $quality,
            qualityReason: $reason,
            scope: [
                'kind' => 'science_park',
                'authority' => $this->source['authority'],
                'dataset_page' => $this->source['dataset_page'],
                'parks' => array_keys($metrics),
                'roc_year' => $latestRocYear,
                'granularity' => 'monthly',
            ],
            metrics: $metrics,
            units: [
                'monthly' => $unit ?? 'CMD（立方公尺／日）',
                'latest_value' => $unit ?? 'CMD（立方公尺／日）',
                'median_value' => $unit ?? 'CMD（立方公尺／日）',
                'demand_index' => '比值（最新月份 ÷ 同園區有效月份中位數）',
            ],
            warnings: $warnings,
            byteSize: $payload->byteSize,
        );
    }

    /**
     * @param  list<string>  $header
     * @return array<int, string> 月份 => 欄位名稱
     */
    private function monthColumns(array $header): array
    {
        $columns = [];

        foreach ($header as $column) {
            if (preg_match('/^(\d{1,2})月/u', $column, $matches) === 1) {
                $columns[(int) $matches[1]] = $column;
            }
        }

        ksort($columns);

        return $columns;
    }

    private function parseRocYear(string $value): ?int
    {
        return preg_match('/(\d{2,3})\s*年/u', $value, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function parseUnit(string $value): ?string
    {
        return preg_match('/單位[：:]\s*([^)）]+)/u', $value, $matches) === 1
            ? trim($matches[1])
            : null;
    }

    private function period(int $rocYear): SnapshotPeriod
    {
        $year = $rocYear + 1911;

        return new SnapshotPeriod(
            CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'Asia/Taipei')->utc(),
            CarbonImmutable::create($year, 12, 31, 23, 59, 59, 'Asia/Taipei')->utc(),
            "民國 {$rocYear} 年（西元 {$year} 年）",
            'roc',
        );
    }

    /**
     * 年度統計以資料涵蓋年度判斷，不用抓取時間。
     *
     * @return array{0: SnapshotQuality, 1: string}
     */
    private function assessQuality(int $rocYear, CarbonImmutable $fetchedAt): array
    {
        $currentRocYear = $fetchedAt->setTimezone('Asia/Taipei')->year - 1911;
        $allowedLag = (int) $this->source['stale_after_roc_years'];

        if ($currentRocYear - $rocYear > $allowedLag) {
            return [SnapshotQuality::Stale, "最新年度為民國 {$rocYear} 年，落後超過 {$allowedLag} 年"];
        }

        return [SnapshotQuality::Fresh, "最新年度為民國 {$rocYear} 年，在允許落後 {$allowedLag} 年內"];
    }
}
