<?php

namespace Tests\Feature\OpenData;

use App\Services\OpenData\Exceptions\DataSourceException;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use Tests\TestCase;

class LandUseAdapterTest extends TestCase
{
    use InteractsWithOpenDataFixtures;

    private const SOURCE_ID = 'moi.land_use';

    private const HEADER = '縣市,鄉鎮市,面積單位,水田010101用地面積,闊葉林020200用地面積,純住宅050200用地面積,公園綠地廣場070200用地面積';

    public function test_it_extracts_all_thirteen_hsinchu_county_townships(): void
    {
        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize(
            $this->payload(self::SOURCE_ID, $this->fixture('moi_land_use.sample.csv')),
        );

        $this->assertSame(SnapshotQuality::Fresh, $snapshot->quality);
        $this->assertSame(13, $snapshot->scope['township_count']);
        $this->assertCount(14, $snapshot->metrics); // 13 個鄉鎮市加上縣彙總
        $this->assertSame('新竹縣', $snapshot->metrics['_county']['name']);
        $this->assertArrayHasKey('竹北市', $snapshot->metrics);
        $this->assertArrayNotHasKey('三星鄉', $snapshot->metrics);
    }

    public function test_it_computes_ratios_against_the_row_total_only(): void
    {
        $csv = self::HEADER."\n".'新竹縣,竹北市,公頃,100,200,600,100';

        $snapshot = $this->adapterFor(self::SOURCE_ID, ['expected_township_count' => 1])->normalize(
            $this->payload(self::SOURCE_ID, $csv),
        );

        $metrics = $snapshot->metrics['竹北市'];

        $this->assertSame(1000.0, $metrics['total_area']);
        $this->assertSame(0.1, $metrics['agriculture_ratio']);
        $this->assertSame(0.2, $metrics['forest_ratio']);
        $this->assertSame(0.6, $metrics['built_up_ratio']);
        $this->assertSame(0.1, $metrics['park_green_ratio']);
        $this->assertSame('公頃', $snapshot->units['total_area']);
    }

    public function test_it_excludes_missing_cells_from_the_denominator_and_warns(): void
    {
        $csv = self::HEADER."\n".'新竹縣,竹北市,公頃,100,--,600,0';

        $snapshot = $this->adapterFor(self::SOURCE_ID, ['expected_township_count' => 1])->normalize(
            $this->payload(self::SOURCE_ID, $csv),
        );

        $metrics = $snapshot->metrics['竹北市'];

        $this->assertSame(700.0, $metrics['total_area']);
        $this->assertSame(0.0, $metrics['forest_area']);
        $this->assertSame(0.0, $metrics['park_green_area']);
        $this->assertSame(1, $metrics['missing_category_cells']);
        $this->assertContains('missing_category_cells', array_column($snapshot->warnings, 'code'));
    }

    public function test_it_warns_when_the_township_count_does_not_match_expectations(): void
    {
        $csv = self::HEADER."\n".'新竹縣,竹北市,公頃,100,200,600,100';

        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $csv));

        $this->assertContains('township_count_mismatch', array_column($snapshot->warnings, 'code'));
    }

    public function test_it_labels_park_green_area_as_a_proxy_rather_than_a_heat_measurement(): void
    {
        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize(
            $this->payload(self::SOURCE_ID, $this->fixture('moi_land_use.sample.csv')),
        );

        $this->assertContains('not_a_heat_measurement', array_column($snapshot->warnings, 'code'));
        $this->assertFalse($snapshot->scope['administrative_codes_available']);
    }

    public function test_it_marks_an_outdated_survey_period_as_stale(): void
    {
        $snapshot = $this->adapterFor(self::SOURCE_ID, ['survey_end_roc_year' => 108, 'survey_label' => '107-108年'])
            ->normalize($this->payload(self::SOURCE_ID, $this->fixture('moi_land_use.sample.csv')));

        $this->assertSame(SnapshotQuality::Stale, $snapshot->quality);
    }

    public function test_it_fails_when_the_county_is_absent(): void
    {
        $csv = self::HEADER."\n".'宜蘭縣,三星鄉,公頃,100,200,600,100';

        try {
            $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $csv));
            $this->fail('應該因為找不到新竹縣資料列而失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('missing_required_fields', $exception->reasonCode);
        }
    }

    public function test_it_fails_when_the_header_has_no_category_columns(): void
    {
        $csv = "縣市,鄉鎮市,面積單位\n新竹縣,竹北市,公頃";

        try {
            $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $csv));
            $this->fail('應該因為沒有分類欄位而失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('missing_required_fields', $exception->reasonCode);
        }
    }
}
