<?php

namespace Tests\Feature\OpenData;

use App\Services\OpenData\Exceptions\DataSourceException;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use Tests\TestCase;

class ReservoirConditionsAdapterTest extends TestCase
{
    use InteractsWithOpenDataFixtures;

    private const SOURCE_ID = 'wra.reservoir_conditions';

    public function test_it_normalizes_tracked_reservoirs_from_a_real_upstream_sample(): void
    {
        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize(
            $this->payload(self::SOURCE_ID, $this->fixture('wra_reservoir_conditions.sample.json')),
        );

        $this->assertSame(SnapshotQuality::Fresh, $snapshot->quality);
        $this->assertSame(['10405', '10501'], $snapshot->scope['reservoir_ids']);
        $this->assertSame(['10405', '10501'], array_map(strval(...), array_keys($snapshot->metrics)));
        $this->assertSame('寶山第二水庫', $snapshot->metrics['10405']['name']);
        $this->assertSame(3389.78, $snapshot->metrics['10405']['effective_storage_latest']);
        $this->assertSame(151.43, $snapshot->metrics['10405']['water_level_latest']);
        $this->assertNotNull($snapshot->metrics['10501']['storage_index']);
    }

    public function test_it_records_the_reservoir_county_relation_separately_from_the_name(): void
    {
        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize(
            $this->payload(self::SOURCE_ID, $this->fixture('wra_reservoir_conditions.sample.json')),
        );

        $this->assertSame('新竹縣', $snapshot->metrics['10405']['county']);
        $this->assertSame('located_in_hsinchu_county', $snapshot->metrics['10405']['relation']);
        $this->assertSame('苗栗縣', $snapshot->metrics['10501']['county']);
        $this->assertSame('supplies_hsinchu_area', $snapshot->metrics['10501']['relation']);
    }

    public function test_it_never_derives_a_storage_percentage(): void
    {
        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize(
            $this->payload(self::SOURCE_ID, $this->fixture('wra_reservoir_conditions.sample.json')),
        );

        $this->assertArrayNotHasKey('storage_percentage', $snapshot->metrics['10405']);
        $this->assertContains('storage_percentage_unavailable', array_column($snapshot->warnings, 'code'));
    }

    public function test_it_treats_zero_as_a_value_and_blank_as_missing(): void
    {
        $body = json_encode([
            $this->observation('10405', '2026-09-07T22:00:00', storage: '0.0', rainfall: '0.0'),
            $this->observation('10501', '2026-09-07T22:00:00', storage: '', rainfall: ''),
        ]);

        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $body));

        $this->assertSame(0.0, $snapshot->metrics['10405']['effective_storage_latest']);
        $this->assertSame(0.0, $snapshot->metrics['10405']['catchment_rainfall_latest']);
        $this->assertNull($snapshot->metrics['10501']['effective_storage_latest']);
        $this->assertNull($snapshot->metrics['10501']['catchment_rainfall_latest']);
        $this->assertContains('missing_effective_storage', array_column($snapshot->warnings, 'code'));
    }

    public function test_it_leaves_the_storage_index_empty_when_there_are_too_few_samples(): void
    {
        $body = json_encode([
            $this->observation('10405', '2026-09-07T22:00:00', storage: '3390.0'),
            $this->observation('10405', '2026-09-07T21:00:00', storage: '3380.0'),
        ]);

        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $body));

        $this->assertSame(2, $snapshot->metrics['10405']['sample_count']);
        $this->assertNull($snapshot->metrics['10405']['storage_index']);
        $this->assertContains('insufficient_samples', array_column($snapshot->warnings, 'code'));
    }

    public function test_it_marks_old_observations_as_stale_without_changing_the_values(): void
    {
        $body = json_encode([$this->observation('10405', '2026-09-01T08:00:00', storage: '3390.0')]);

        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $body));

        $this->assertSame(SnapshotQuality::Stale, $snapshot->quality);
        $this->assertSame(3390.0, $snapshot->metrics['10405']['effective_storage_latest']);
    }

    public function test_it_rejects_a_payload_that_is_missing_required_fields(): void
    {
        $body = json_encode([['reservoiridentifier' => '10405', 'observationtime' => '2026-09-07T22:00:00']]);

        $this->expectException(DataSourceException::class);
        $this->expectExceptionMessage('缺少必要欄位');

        $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $body));
    }

    public function test_it_fails_when_no_tracked_reservoir_is_present(): void
    {
        $body = json_encode([$this->observation('50303', '2026-09-07T22:00:00', storage: '0.1')]);

        try {
            $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $body));
            $this->fail('應該因為沒有追蹤中的水庫而失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('missing_required_fields', $exception->reasonCode);
        }
    }

    public function test_it_rejects_a_body_that_is_not_json(): void
    {
        try {
            $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, 'not json at all'));
            $this->fail('應該因為 JSON 無法解析而失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('payload_unparsable', $exception->reasonCode);
        }
    }

    /**
     * @return array<string, string>
     */
    private function observation(string $identifier, string $time, string $storage = '', string $rainfall = ''): array
    {
        return [
            'reservoiridentifier' => $identifier,
            'observationtime' => $time,
            'effectivewaterstoragecapacity' => $storage,
            'waterlevel' => '150.0',
            'accumulaterainfallincatchment' => $rainfall,
            'totaloutflow' => '1.0',
        ];
    }
}
