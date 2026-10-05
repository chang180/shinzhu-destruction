<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\PlannerOneMistakeStrategy;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Services\Game\DifficultyReport;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $firstCsv = $this->scratchPath('a.csv');
        $secondCsv = $this->scratchPath('b.csv');
        $firstMistakes = $this->scratchPath('a-mistakes.csv');
        $secondMistakes = $this->scratchPath('b-mistakes.csv');
        $options = ['--seeds' => 3, '--level' => ['noon-fold', 'meter-feast']];

        $this->artisan('game:difficulty-report', $options + ['--json' => $first, '--csv' => $firstCsv, '--mistakes-csv' => $firstMistakes])->assertSuccessful();
        $this->artisan('game:difficulty-report', $options + ['--json' => $second, '--csv' => $secondCsv, '--mistakes-csv' => $secondMistakes])->assertSuccessful();

        $this->assertFileEquals($first, $second);
        $this->assertFileEquals($firstCsv, $secondCsv);
        $this->assertFileEquals($firstMistakes, $secondMistakes);
        // 每個 one-mistake 格 3 seed，各一列，加表頭。
        $this->assertCount((1 + 2) * 9 * 3 + 1, file($firstMistakes));
        $report = json_decode((string) file_get_contents($first), true);
        // noon-fold：1 牌組，meter-feast：2 牌組；9 情境 × 7 策略。
        $this->assertCount((1 + 2) * 9 * 7, $report['cells']);
        $this->assertCount(count($report['cells']) + 1, file($firstCsv));
        $this->assertSame('p10-di-2', $report['meta']['index_version']);
        $this->assertSame(['noon-fold', 'meter-feast'], array_column($report['levels'], 'level'));
        $this->assertSame(0, array_sum(array_column($report['cells'], 'illegal_choices')));

        array_map('unlink', [$first, $second, $firstCsv, $secondCsv, $firstMistakes, $secondMistakes]);
    }

    public function test_recovery_rate_pairs_each_mistake_game_with_the_planner_on_the_same_seed(): void
    {
        $level = app(LevelRepository::class)->get('noon-fold');
        $modifiers = new ScenarioModifiers(
            array_fill_keys(Element::values(), 0.0),
            array_fill_keys(Element::values(), ['code' => 'test', 'message' => '', 'inputs' => []]),
        );
        $simulator = new BattleSimulator(app(BattleEngine::class));
        $planner = new PlannerStrategy($modifiers, app(CardCatalog::class));
        $mistake = new PlannerOneMistakeStrategy($modifiers, app(CardCatalog::class));
        $expected = ['planner_baseline_wins' => 0, 'mistakes_injected' => 0, 'eligible_mistake_games' => 0, 'recoveries_after_mistake' => 0];

        for ($seed = 1; $seed <= 20; $seed++) {
            $baselineWon = $simulator->run($level, $modifiers, $planner, $seed, 'w0h0l0')->won();
            $after = $simulator->run($level, $modifiers, $mistake, $seed, 'w0h0l0');
            $injected = $after->strategyReport['status'] === PlannerOneMistakeStrategy::STATUS_INJECTED;

            $expected['planner_baseline_wins'] += $baselineWon ? 1 : 0;
            $expected['mistakes_injected'] += $injected ? 1 : 0;
            $expected['eligible_mistake_games'] += $baselineWon && $injected ? 1 : 0;
            $expected['recoveries_after_mistake'] += $baselineWon && $injected && $after->won() ? 1 : 0;
        }

        // 只要求 one-mistake：planner 基準仍要在內部跑，配對才成立。
        $rows = [];
        $report = app(DifficultyReport::class)->build('quick', 20, 1, ['noon-fold'], ['planner-one-mistake'], onMistakeGame: function (array $row) use (&$rows): void {
            $rows[] = $row;
        });
        $cell = collect($report['cells'])->firstWhere('scenario', 'w0h0l0');
        $cellRows = array_values(array_filter($rows, static fn (array $row): bool => $row['scenario'] === 'w0h0l0'));

        $this->assertSame($expected, array_intersect_key($cell, $expected));
        // 這組 seed 裡 planner 有輸的局：它們注入了失誤，但不進恢復率分母。
        $this->assertGreaterThan($expected['eligible_mistake_games'], $expected['mistakes_injected']);
        $this->assertSame(round($expected['recoveries_after_mistake'] / $expected['eligible_mistake_games'], 4), $cell['recovery_rate']);
        $this->assertCount(20, $cellRows);
        $this->assertSame($expected['eligible_mistake_games'], count(array_filter($cellRows, static fn (array $row): bool => $row['planner_won'] && $row['status'] === 'injected')));
        $this->assertSame(array_sum($cell['mistake_turns']), $expected['mistakes_injected']);
        $this->assertNotContains('planner', array_column($report['cells'], 'strategy'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): array
    {
        return [
            'unknown strategy' => [['--strategy' => ['typo']]],
            'unknown level' => [['--level' => ['nowhere']]],
            'zero seeds' => [['--seeds' => 0]],
            'negative seeds' => [['--seeds' => -1]],
            'non-numeric seeds' => [['--seeds' => 'many']],
            'unknown suite' => [['--suite' => 'huge']],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('invalidOptions')]
    public function test_invalid_options_exit_with_an_error_and_write_no_report(array $options): void
    {
        $json = $this->scratchPath('invalid.json');

        $this->artisan('game:difficulty-report', $options + ['--json' => $json])->assertExitCode(2);

        $this->assertFileDoesNotExist($json);
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
        $report = app(DifficultyReport::class)->build('quick', 2, 1, ['empty-cup', 'noon-fold'], ['planner', 'forecast-aware']);

        $this->assertNull($report['levels'][0]['difficulty']['difficulty_index']);
        $this->assertNull($report['progression']['passes']);
    }
}
