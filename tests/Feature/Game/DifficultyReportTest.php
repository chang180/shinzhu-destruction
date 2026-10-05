<?php

namespace Tests\Feature\Game;

use App\Domain\Game\LevelRepository;
use App\Services\Game\DifficultyReport;
use Tests\TestCase;

class DifficultyReportTest extends TestCase
{
    private function scratchPath(string $name): string
    {
        return sys_get_temp_dir().'/p10-'.getmypid().'-'.$name;
    }

    public function test_quick_suite_has_neutral_uniform_and_single_element_scenarios(): void
    {
        $labels = array_keys(app(DifficultyReport::class)->scenarios('quick'));

        $this->assertSame(
            ['w0h0l0', 'w-h-l-', 'w+h+l+', 'w-h0l0', 'w+h0l0', 'w0h-l0', 'w0h+l0', 'w0h0l-', 'w0h0l+'],
            $labels,
        );
    }

    public function test_full_suite_covers_all_27_permutations_at_the_modifier_limit(): void
    {
        $scenarios = app(DifficultyReport::class)->scenarios('full');

        $this->assertCount(27, $scenarios);
        $this->assertSame(['water' => 0.15, 'heat' => -0.15, 'land' => 0.0], $scenarios['w+h-l0']);
    }

    public function test_deck_variants_follow_the_rewards_a_player_can_hold_at_each_level(): void
    {
        $report = app(DifficultyReport::class);
        $levels = app(LevelRepository::class);

        $variants = array_map(
            static fn (string $id): array => array_keys($report->deckVariants($levels->get($id))),
            ['empty-cup' => 'empty-cup', 'noon-fold' => 'noon-fold', 'meter-feast' => 'meter-feast', 'mirror-shade' => 'mirror-shade', 'stored-night' => 'stored-night'],
        );

        $this->assertSame([
            'empty-cup' => ['starter'],
            'noon-fold' => ['starter'],
            'meter-feast' => ['tide-siege', 'smoke-screen'],
            'mirror-shade' => ['tide-siege', 'smoke-screen'],
            'stored-night' => ['tide-siege+hollow-ground', 'tide-siege+sluice-jam', 'smoke-screen+hollow-ground', 'smoke-screen+sluice-jam'],
        ], $variants);
    }

    public function test_two_water_rewards_replace_two_long_flow_cards_and_keep_15_cards(): void
    {
        $level = app(LevelRepository::class)->get('stored-night');

        $deck = app(DifficultyReport::class)->deckVariants($level)['tide-siege+sluice-jam'];

        $this->assertSame(1, $deck['long-flow']);
        $this->assertSame(1, $deck['tide-siege']);
        $this->assertSame(1, $deck['sluice-jam']);
        $this->assertSame(15, array_sum($deck));
    }

    public function test_same_parameters_and_seeds_write_identical_reports(): void
    {
        $first = $this->scratchPath('a.json');
        $second = $this->scratchPath('b.json');
        $csv = $this->scratchPath('cells.csv');

        $this->artisan('game:difficulty-report', ['--seeds' => 3, '--level' => ['noon-fold', 'meter-feast'], '--json' => $first, '--csv' => $csv])->assertSuccessful();
        $this->artisan('game:difficulty-report', ['--seeds' => 3, '--level' => ['noon-fold', 'meter-feast'], '--json' => $second])->assertSuccessful();

        $this->assertFileEquals($first, $second);
        $report = json_decode((string) file_get_contents($first), true);
        // noon-fold：1 牌組，meter-feast：2 牌組；9 情境 × 7 策略。
        $this->assertCount((1 + 2) * 9 * 7, $report['cells']);
        $this->assertCount(count($report['cells']) + 1, file($csv));
        $this->assertSame(['noon-fold', 'meter-feast'], array_column($report['levels'], 'level'));
        $this->assertSame(0, array_sum(array_column($report['cells'], 'illegal_choices')));

        @unlink($first);
        @unlink($second);
        @unlink($csv);
    }

    public function test_difficulty_index_uses_the_p10_weights(): void
    {
        $report = app(DifficultyReport::class)->build('quick', 2, 1, ['empty-cup']);

        $difficulty = $report['levels'][0]['difficulty'];

        $this->assertEqualsWithDelta(
            0.35 * (1 - $difficulty['planner_win_rate'])
            + 0.25 * (1 - $difficulty['forecast_aware_win_rate'])
            + 0.25 * (1 - $difficulty['one_mistake_recovery_rate'])
            + 0.15 * $difficulty['planner_winning_turn_budget_used'],
            $difficulty['difficulty_index'],
            0.0001,
        );
    }

    public function test_difficulty_index_is_withheld_when_a_component_strategy_was_not_run(): void
    {
        $report = app(DifficultyReport::class)->build('quick', 2, 1, ['empty-cup', 'noon-fold'], ['planner']);

        $this->assertNull($report['levels'][0]['difficulty']['difficulty_index']);
        $this->assertNull($report['progression']['passes']);
    }

    public function test_unknown_suite_is_rejected(): void
    {
        $this->artisan('game:difficulty-report', ['--suite' => 'huge'])->assertFailed();
    }
}
