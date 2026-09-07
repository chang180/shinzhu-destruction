<?php

namespace Tests\Feature\OpenData;

use App\Services\OpenData\Exceptions\DataSourceException;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use Tests\TestCase;

class ScienceParkWaterAdapterTest extends TestCase
{
    use InteractsWithOpenDataFixtures;

    private const SOURCE_ID = 'nstc.science_park_water';

    private const HEADER = '園區名稱,年度,1月數值,2月數值,3月數值,4月數值,5月數值,6月數值,7月數值,8月數值,9月數值,10月數值,11月數值,12月數值';

    public function test_it_normalizes_every_park_from_a_real_upstream_sample(): void
    {
        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize(
            $this->payload(self::SOURCE_ID, $this->fixture('nstc_science_park_water.sample.csv')),
        );

        $this->assertSame(SnapshotQuality::Fresh, $snapshot->quality);
        $this->assertSame(114, $snapshot->scope['roc_year']);
        $this->assertSame(12, $snapshot->metrics['新竹園區']['valid_months']);
        $this->assertSame(186988.0, $snapshot->metrics['新竹園區']['latest_value']);
        $this->assertSame('CMD', $snapshot->units['monthly']);
    }

    public function test_it_records_the_roc_year_as_a_period_with_a_western_year(): void
    {
        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize(
            $this->payload(self::SOURCE_ID, $this->fixture('nstc_science_park_water.sample.csv')),
        );

        $this->assertNotNull($snapshot->period);
        $this->assertSame('roc', $snapshot->period->calendar);
        $this->assertSame('民國 114 年（西元 2025 年）', $snapshot->period->label);
        $this->assertNull($snapshot->observedAt);
    }

    public function test_it_keeps_parks_outside_hsinchu_flagged(): void
    {
        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize(
            $this->payload(self::SOURCE_ID, $this->fixture('nstc_science_park_water.sample.csv')),
        );

        $this->assertTrue($snapshot->metrics['新竹園區']['in_hsinchu']);
        $this->assertTrue($snapshot->metrics['生醫園區']['in_hsinchu']);
        $this->assertFalse($snapshot->metrics['竹南園區']['in_hsinchu']);
        $this->assertSame('苗栗縣', $snapshot->metrics['竹南園區']['county']);
    }

    public function test_it_reports_missing_months_instead_of_substituting_zero(): void
    {
        $csv = self::HEADER."\n".'新竹園區,114年用水量(單位：CMD),165344,,168554,173734,179396,185609,191153,193431,197228,196985,189497,186988';

        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $csv));

        $this->assertNull($snapshot->metrics['新竹園區']['monthly'][2]);
        $this->assertSame(11, $snapshot->metrics['新竹園區']['valid_months']);
        $this->assertContains('missing_month', array_column($snapshot->warnings, 'code'));
    }

    public function test_it_skips_the_demand_index_when_valid_months_fall_below_the_threshold(): void
    {
        $csv = self::HEADER."\n".'新竹園區,114年用水量(單位：CMD),165344,163352,168554,,,,,,,,,';

        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $csv));

        $this->assertSame(3, $snapshot->metrics['新竹園區']['valid_months']);
        $this->assertNull($snapshot->metrics['新竹園區']['demand_index']);
        $this->assertContains('insufficient_samples', array_column($snapshot->warnings, 'code'));
    }

    public function test_it_marks_an_old_reporting_year_as_stale(): void
    {
        $csv = self::HEADER."\n".'新竹園區,110年用水量(單位：CMD),1,2,3,4,5,6,7,8,9,10,11,12';

        $snapshot = $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $csv));

        $this->assertSame(SnapshotQuality::Stale, $snapshot->quality);
        $this->assertSame(110, $snapshot->metrics['新竹園區']['roc_year']);
    }

    public function test_it_rejects_a_csv_without_the_month_columns(): void
    {
        $csv = "園區名稱,年度\n新竹園區,114年用水量(單位：CMD)";

        try {
            $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $csv));
            $this->fail('應該因為月份欄位不足而失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('missing_required_fields', $exception->reasonCode);
        }
    }

    public function test_it_rejects_a_csv_without_the_expected_header(): void
    {
        $csv = "a,b\n1,2";

        try {
            $this->adapterFor(self::SOURCE_ID)->normalize($this->payload(self::SOURCE_ID, $csv));
            $this->fail('應該因為缺少必要欄位而失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('missing_required_fields', $exception->reasonCode);
        }
    }
}
