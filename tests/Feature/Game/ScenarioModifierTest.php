<?php

namespace Tests\Feature\Game;

use App\Domain\Game\Element;
use App\Domain\Game\Scenario\ScenarioModifierCalculator;
use App\Services\OpenData\Snapshot\NormalizedSnapshot;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ScenarioModifierTest extends TestCase
{
    private function calculator(): ScenarioModifierCalculator
    {
        return app(ScenarioModifierCalculator::class);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function snapshot(string $sourceId, array $metrics): NormalizedSnapshot
    {
        return new NormalizedSnapshot(
            snapshotId: 'test-'.$sourceId,
            sourceId: $sourceId,
            schemaVersion: '1.0.0',
            resourceUrl: 'test://'.$sourceId,
            contentHash: str_repeat('0', 64),
            fetchedAt: CarbonImmutable::parse('2026-09-08T00:00:00Z'),
            observedAt: null,
            period: null,
            quality: SnapshotQuality::Fresh,
            qualityReason: 'test',
            scope: [],
            metrics: $metrics,
            units: [],
        );
    }

    public function test_every_modifier_stays_inside_the_declared_limit(): void
    {
        $limit = (float) config('game.scenario.modifier_limit');

        $extreme = $this->calculator()->calculate([
            'wra.reservoir_conditions' => $this->snapshot('wra.reservoir_conditions', [
                '10405' => ['storage_index' => 9.0],
                '10501' => ['storage_index' => 9.0],
            ]),
            'nstc.science_park_water' => $this->snapshot('nstc.science_park_water', [
                '新竹園區' => ['in_hsinchu' => true, 'demand_index' => 0.0],
            ]),
            'moi.land_use' => $this->snapshot('moi.land_use', [
                'a' => ['built_up_ratio' => 0.0, 'forest_ratio' => 0.0, 'park_green_ratio' => 0.0],
                'b' => ['built_up_ratio' => 1.0, 'forest_ratio' => 1.0, 'park_green_ratio' => 1.0],
                '_county' => ['built_up_ratio' => 1.0, 'forest_ratio' => 1.0, 'park_green_ratio' => 1.0],
            ]),
        ]);

        foreach (Element::all() as $element) {
            $this->assertGreaterThanOrEqual(-$limit, $extreme->for($element));
            $this->assertLessThanOrEqual($limit, $extreme->for($element));
        }
    }

    public function test_missing_metrics_fall_back_to_neutral_instead_of_guessing(): void
    {
        $modifiers = $this->calculator()->calculate([]);

        foreach (Element::all() as $element) {
            $this->assertSame(0.0, $modifiers->for($element));
            $this->assertSame('missing_inputs', $modifiers->reasons[$element->value]['code']);
        }
    }

    public function test_a_null_storage_index_does_not_become_one_point_zero(): void
    {
        $modifiers = $this->calculator()->calculate([
            'wra.reservoir_conditions' => $this->snapshot('wra.reservoir_conditions', [
                '10405' => ['storage_index' => null],
                '10501' => ['storage_index' => null],
            ]),
        ]);

        $this->assertSame(0.0, $modifiers->for(Element::Water));
        $this->assertSame('missing_inputs', $modifiers->reasons['water']['code']);
    }

    public function test_full_reservoirs_make_the_city_harder_and_high_demand_makes_it_easier(): void
    {
        $full = $this->calculator()->calculate([
            'wra.reservoir_conditions' => $this->snapshot('wra.reservoir_conditions', [
                '10405' => ['storage_index' => 1.20],
                '10501' => ['storage_index' => 1.20],
            ]),
        ]);

        $empty = $this->calculator()->calculate([
            'wra.reservoir_conditions' => $this->snapshot('wra.reservoir_conditions', [
                '10405' => ['storage_index' => 0.80],
                '10501' => ['storage_index' => 0.80],
            ]),
        ]);

        $this->assertLessThan(0, $full->for(Element::Water));
        $this->assertGreaterThan(0, $empty->for(Element::Water));
    }

    public function test_only_hsinchu_parks_feed_the_water_demand_signal(): void
    {
        // 竹南園區在苗栗縣，不能拿來當新竹的需求訊號。
        $miaoliOnly = $this->calculator()->calculate([
            'nstc.science_park_water' => $this->snapshot('nstc.science_park_water', [
                '竹南園區' => ['in_hsinchu' => false, 'demand_index' => 2.0],
            ]),
        ]);

        $this->assertSame(0.0, $miaoliOnly->for(Element::Water));
        $this->assertSame('missing_inputs', $miaoliOnly->reasons['water']['code']);
    }

    public function test_green_share_and_built_up_share_push_in_opposite_directions(): void
    {
        $snapshots = [
            'moi.land_use' => $this->snapshot('moi.land_use', [
                'low' => ['built_up_ratio' => 0.02, 'forest_ratio' => 0.10, 'park_green_ratio' => 0.001],
                'high' => ['built_up_ratio' => 0.30, 'forest_ratio' => 0.90, 'park_green_ratio' => 0.02],
                // 全縣值貼近綠地分布上緣、建成地分布上緣。
                '_county' => ['built_up_ratio' => 0.30, 'forest_ratio' => 0.90, 'park_green_ratio' => 0.02],
            ]),
        ];

        $modifiers = $this->calculator()->calculate($snapshots);

        // 建成地佔比高 → 熱系好打（正值）；綠地佔比高 → 土地系難打（負值）。
        $this->assertGreaterThan(0, $modifiers->for(Element::Heat));
        $this->assertLessThan(0, $modifiers->for(Element::Land));
    }

    public function test_the_reasons_name_the_inputs_so_the_briefing_can_show_them(): void
    {
        $modifiers = $this->calculator()->calculate([
            'wra.reservoir_conditions' => $this->snapshot('wra.reservoir_conditions', [
                '10405' => ['storage_index' => 1.05],
            ]),
        ]);

        $reason = $modifiers->reasons['water'];

        $this->assertSame('reservoir_and_demand', $reason['code']);
        $this->assertArrayHasKey('storage_index_mean', $reason['inputs']);
        $this->assertSame(1.05, $reason['inputs']['storage_index_mean']);
    }
}
