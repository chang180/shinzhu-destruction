<?php

namespace App\Services\OpenData\Adapters;

use App\Services\OpenData\Exceptions\DataSourceException;
use App\Services\OpenData\Snapshot\NormalizedSnapshot;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use App\Services\OpenData\Support\Metrics;
use App\Services\OpenData\Support\RawPayload;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * 經濟部水利署「水庫水情資料」（data.gov.tw 45501）。
 *
 * 這份資源只提供 reservoiridentifier，不含水庫名稱，也**沒有**上游說明提到的
 * 蓄水百分比欄位；因此本 adapter 不計算蓄水率，只輸出同一水庫自身的
 * 有效蓄水量相對指標（最新值 ÷ 觀測視窗中位數），並保留原始水位與降雨量。
 */
class ReservoirConditionsAdapter extends AbstractAdapter
{
    private const SOURCE_TIMEZONE = 'Asia/Taipei';

    private const REQUIRED_FIELDS = [
        'reservoiridentifier',
        'observationtime',
        'effectivewaterstoragecapacity',
        'waterlevel',
    ];

    public function normalize(RawPayload $payload): NormalizedSnapshot
    {
        $rows = $this->decode($payload);
        $warnings = [];
        $metrics = [];
        $latestObservations = [];

        /** @var array<string, array{name: string, county: string, relation: string}> $tracked */
        $tracked = $this->source['reservoirs'];
        $windowHours = (int) $this->source['observation_window_hours'];
        $minimumSamples = (int) $this->source['minimum_samples'];

        $newestOverall = $this->newestObservationTime($rows);

        foreach ($tracked as $identifier => $meta) {
            $reservoirRows = $this->rowsFor($rows, (string) $identifier);

            if ($reservoirRows === []) {
                $warnings[] = $this->warning(
                    'reservoir_missing',
                    "上游沒有水庫代碼 {$identifier} 的觀測列",
                    ['reservoir_id' => (string) $identifier],
                );

                $metrics[(string) $identifier] = $this->emptyReservoirMetrics($meta);

                continue;
            }

            $windowStart = $newestOverall?->subHours($windowHours);
            $windowed = array_values(array_filter(
                $reservoirRows,
                static fn (array $row): bool => $windowStart === null || $row['observed_at']->greaterThanOrEqualTo($windowStart),
            ));

            usort(
                $windowed,
                static fn (array $a, array $b): int => $a['observed_at']->getTimestamp() <=> $b['observed_at']->getTimestamp(),
            );

            $latest = $windowed === [] ? null : $windowed[count($windowed) - 1];

            if ($latest === null) {
                $metrics[(string) $identifier] = $this->emptyReservoirMetrics($meta);

                continue;
            }

            $storageSamples = array_values(array_filter(
                array_map(static fn (array $row): ?float => $row['effective_storage'], $windowed),
                static fn (?float $value): bool => $value !== null,
            ));

            if ($latest['effective_storage'] === null) {
                $warnings[] = $this->warning(
                    'missing_effective_storage',
                    "水庫 {$identifier} 最新觀測沒有有效蓄水量，相對指標留空",
                    ['reservoir_id' => (string) $identifier],
                );
            }

            $storageIndex = Metrics::relativeIndex($latest['effective_storage'], $storageSamples, $minimumSamples);

            if ($storageIndex === null && $latest['effective_storage'] !== null) {
                $warnings[] = $this->warning(
                    'insufficient_samples',
                    "水庫 {$identifier} 在 {$windowHours} 小時視窗內少於 {$minimumSamples} 筆有效蓄水量，不計算相對指標",
                    ['reservoir_id' => (string) $identifier, 'samples' => count($storageSamples)],
                );
            }

            $latestObservations[] = $latest['observed_at'];

            $metrics[(string) $identifier] = [
                'name' => $meta['name'],
                'county' => $meta['county'],
                'relation' => $meta['relation'],
                'observed_at' => $latest['observed_at']->utc()->toIso8601String(),
                'sample_count' => count($storageSamples),
                'effective_storage_latest' => Metrics::round($latest['effective_storage'], 2),
                'effective_storage_median' => Metrics::median($storageSamples),
                'storage_index' => $storageIndex,
                'water_level_latest' => Metrics::round($latest['water_level'], 2),
                'catchment_rainfall_latest' => Metrics::round($latest['catchment_rainfall'], 1),
                'total_outflow_latest' => Metrics::round($latest['total_outflow'], 2),
            ];
        }

        if ($latestObservations === []) {
            throw DataSourceException::missingRequiredFields(
                $this->sourceId,
                '追蹤的水庫代碼在本次回應中都沒有可用觀測',
                ['reservoir_ids' => array_keys($tracked)],
            );
        }

        $warnings[] = $this->warning(
            'storage_percentage_unavailable',
            '本資源未提供蓄水百分比與滿水位容量欄位，不推算蓄水率',
        );

        $observedAt = max($latestObservations);
        [$quality, $reason] = $this->assessQuality($observedAt, $payload->fetchedAt);

        return new NormalizedSnapshot(
            snapshotId: NormalizedSnapshot::newId(),
            sourceId: $this->sourceId,
            schemaVersion: $this->schemaVersion,
            resourceUrl: $payload->resourceUrl,
            contentHash: $payload->contentHash,
            fetchedAt: $payload->fetchedAt,
            observedAt: $observedAt,
            period: null,
            quality: $quality,
            qualityReason: $reason,
            scope: [
                'kind' => 'reservoir',
                'authority' => $this->source['authority'],
                'dataset_page' => $this->source['dataset_page'],
                'reservoir_ids' => array_map(strval(...), array_keys($tracked)),
                'observation_window_hours' => $windowHours,
                'source_timezone' => self::SOURCE_TIMEZONE,
            ],
            metrics: $metrics,
            units: [
                'effective_storage_latest' => '萬立方公尺（上游未於 payload 標示，依水利署資料集說明）',
                'effective_storage_median' => '萬立方公尺（同上）',
                'storage_index' => '比值（最新值 ÷ 同水庫觀測視窗中位數）',
                'water_level_latest' => '公尺',
                'catchment_rainfall_latest' => '毫米',
                'total_outflow_latest' => '立方公尺／秒',
            ],
            warnings: $warnings,
            byteSize: $payload->byteSize,
        );
    }

    /**
     * @return list<array{
     *     identifier: string,
     *     observed_at: CarbonImmutable,
     *     effective_storage: float|null,
     *     water_level: float|null,
     *     catchment_rainfall: float|null,
     *     total_outflow: float|null,
     * }>
     */
    private function decode(RawPayload $payload): array
    {
        try {
            $decoded = json_decode($payload->bodyWithoutBom(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw DataSourceException::payloadUnparsable($this->sourceId, 'JSON 解析失敗');
        }

        if (! is_array($decoded) || $decoded === []) {
            throw DataSourceException::payloadUnparsable($this->sourceId, 'JSON 內容不是非空陣列');
        }

        $first = $decoded[array_key_first($decoded)];

        if (! is_array($first)) {
            throw DataSourceException::payloadUnparsable($this->sourceId, 'JSON 元素不是物件');
        }

        $missing = array_values(array_diff(self::REQUIRED_FIELDS, array_keys($first)));

        if ($missing !== []) {
            throw DataSourceException::missingRequiredFields(
                $this->sourceId,
                '缺少必要欄位：'.implode('、', $missing),
                ['missing_fields' => $missing],
            );
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['reservoiridentifier'], $row['observationtime'])) {
                continue;
            }

            $observedAt = $this->parseObservationTime((string) $row['observationtime']);

            if ($observedAt === null) {
                continue;
            }

            $rows[] = [
                'identifier' => (string) $row['reservoiridentifier'],
                'observed_at' => $observedAt,
                'effective_storage' => $this->toFloat($row['effectivewaterstoragecapacity'] ?? null),
                'water_level' => $this->toFloat($row['waterlevel'] ?? null),
                'catchment_rainfall' => $this->toFloat($row['accumulaterainfallincatchment'] ?? null),
                'total_outflow' => $this->toFloat($row['totaloutflow'] ?? null),
            ];
        }

        if ($rows === []) {
            throw DataSourceException::payloadUnparsable($this->sourceId, '沒有任何可解析的觀測列');
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rowsFor(array $rows, string $identifier): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['identifier'] === $identifier,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function newestObservationTime(array $rows): ?CarbonImmutable
    {
        $times = array_map(static fn (array $row): CarbonImmutable => $row['observed_at'], $rows);

        return $times === [] ? null : max($times);
    }

    /**
     * 上游時間字串沒有時區標示，依水利署資料為台灣本地時間，轉存 UTC。
     */
    private function parseObservationTime(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, self::SOURCE_TIMEZONE)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array{name: string, county: string, relation: string}  $meta
     * @return array<string, mixed>
     */
    private function emptyReservoirMetrics(array $meta): array
    {
        return [
            'name' => $meta['name'],
            'county' => $meta['county'],
            'relation' => $meta['relation'],
            'observed_at' => null,
            'sample_count' => 0,
            'effective_storage_latest' => null,
            'effective_storage_median' => null,
            'storage_index' => null,
            'water_level_latest' => null,
            'catchment_rainfall_latest' => null,
            'total_outflow_latest' => null,
        ];
    }

    /**
     * @return array{0: SnapshotQuality, 1: string}
     */
    private function assessQuality(CarbonImmutable $observedAt, CarbonImmutable $fetchedAt): array
    {
        $staleAfter = (int) $this->source['stale_after_hours'];

        if ($observedAt->diffInHours($fetchedAt, absolute: true) > $staleAfter) {
            return [SnapshotQuality::Stale, "最新觀測超過 {$staleAfter} 小時"];
        }

        return [SnapshotQuality::Fresh, "最新觀測在 {$staleAfter} 小時內"];
    }
}
