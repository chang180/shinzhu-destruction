<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\Strategies\ForecastAwareStrategy;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Simulation\Strategies\RandomStrategy;
use App\Domain\Game\Simulation\Strategy;
use Tests\TestCase;

/**
 * P10-1 把五關包成單一等價關卡幕次；規則行為必須和動工前逐項相同。
 *
 * 期望值來自 P10-1 動工前錄下的 tests/Fixtures/p10-1/equivalence.json（見 meta.generated_at_commit），
 * 不是由現在的程式算出來的。公開局面只比對當時就存在的欄位。
 *
 * 4.0.0（P10-2）改了第 1、2 關的內容，那兩關的錄製只代表 3.1.0 舊局，改由舊局唯讀／重播測試
 * 保護（CounterfactualTest、LegacyRunCompatibilityTest）；第 3～5 關未改，仍須逐項相同。
 */
class LevelPhaseEquivalenceTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private static array $fixture = [];

    private const SCENARIOS = ['low' => -0.15, 'mid' => 0.0, 'high' => 0.15];

    /**
     * 4.0.0 改了內容的關卡；它們的 3.1.0 錄製不再是現行規則的期望值。
     */
    private const CHANGED_IN_4_0_0 = ['empty-cup', 'noon-fold'];

    private function fixture(): array
    {
        return self::$fixture = self::$fixture ?: json_decode((string) file_get_contents(base_path('tests/Fixtures/p10-1/equivalence.json')), true);
    }

    private function recorder(): EquivalenceRecorder
    {
        return new EquivalenceRecorder(app(BattleEngine::class));
    }

    private function strategy(string $name, ScenarioModifiers $modifiers): Strategy
    {
        return match ($name) {
            'planner' => new PlannerStrategy($modifiers, app(CardCatalog::class)),
            'random' => new RandomStrategy,
            'forecast-aware' => new ForecastAwareStrategy(app(CardCatalog::class)),
        };
    }

    public function test_every_level_scenario_strategy_and_seed_replays_the_recorded_actions_events_and_state(): void
    {
        $fixture = $this->fixture();

        foreach ($fixture['games'] as $key => $expected) {
            [$levelId, $scenario, $strategy, $seed] = explode('|', $key);

            if (in_array($levelId, self::CHANGED_IN_4_0_0, true)) {
                continue;
            }
            $modifiers = EquivalenceRecorder::modifiers(self::SCENARIOS[$scenario]);

            $actual = $this->recorder()->record(app(LevelRepository::class)->get($levelId), $modifiers, $this->strategy($strategy, $modifiers), (int) $seed, $fixture['meta']['public_keys']);

            $this->assertSame($expected, $actual, $key);
        }
    }

    public function test_full_event_sequences_match_for_every_level(): void
    {
        $fixture = $this->fixture();

        foreach (array_diff_key($fixture['full_events'], array_flip(self::CHANGED_IN_4_0_0)) as $levelId => $expected) {
            $modifiers = EquivalenceRecorder::modifiers(0.0);

            $actual = $this->recorder()->record(app(LevelRepository::class)->get($levelId), $modifiers, $this->strategy('planner', $modifiers), 1, $fixture['meta']['public_keys'], true);

            $this->assertSame($expected, $actual, $levelId);
        }
    }

    public function test_levels_changed_in_4_0_0_no_longer_reproduce_their_3_1_0_recordings(): void
    {
        $fixture = $this->fixture();

        foreach (self::CHANGED_IN_4_0_0 as $levelId) {
            $modifiers = EquivalenceRecorder::modifiers(0.0);

            $actual = $this->recorder()->record(app(LevelRepository::class)->get($levelId), $modifiers, $this->strategy('planner', $modifiers), 1, $fixture['meta']['public_keys'], true);

            $this->assertNotSame($fixture['full_events'][$levelId], $actual, $levelId);
        }

        $this->assertSame('3.1.0', $fixture['meta']['rules_version']);
        $this->assertNotSame($fixture['meta']['rules_version'], config('game.rules_version'));
    }

    public function test_stored_night_overhaul_start_stop_and_completion_samples_are_unchanged(): void
    {
        $fixture = $this->fixture();

        foreach ($fixture['overhaul'] as $kind => $expected) {
            $modifiers = EquivalenceRecorder::modifiers(self::SCENARIOS[$expected['scenario']]);
            $sample = ['scenario' => $expected['scenario'], 'strategy' => $expected['strategy'], 'seed' => $expected['seed']];

            $actual = $sample + $this->recorder()->record(app(LevelRepository::class)->get('stored-night'), $modifiers, $this->strategy($expected['strategy'], $modifiers), $expected['seed'], $fixture['meta']['public_keys'], true);

            $this->assertSame($expected, $actual, $kind);
            $this->assertContains('overhaul_'.match ($kind) {
                'stopped' => 'stopped',
                'completed' => 'completed',
                'started_then_won' => 'started',
            }, array_column($actual['events'], 'reason_code'));
            $this->assertNotContains('level_phase_change', array_column($actual['events'], 'type'));
        }
    }

    public function test_a_save_written_before_level_phases_restores_and_continues_identically(): void
    {
        $fixture = $this->fixture()['legacy_save'];
        $modifiers = EquivalenceRecorder::modifiers(0.0);
        $this->assertArrayNotHasKey('level_phase_id', $fixture['state']);

        $restored = BattleState::fromArray($fixture['state']);
        $actual = $this->recorder()->record(app(LevelRepository::class)->get('stored-night'), $modifiers, $this->strategy('planner', $modifiers), 1, $this->fixture()['meta']['public_keys'], false, $restored);

        $this->assertSame(array_diff_key($fixture, ['level' => 1, 'seed' => 1, 'state' => 1]), $actual);
        $this->assertSame($fixture['state'], EquivalenceRecorder::pick($restored->toArray(), array_keys($fixture['state'])));
    }

    public function test_counterfactual_continuations_are_unchanged(): void
    {
        foreach ($this->fixture()['counterfactual'] as $key => $expected) {
            [$levelId, $decision] = explode('|', $key);

            if (in_array($levelId, self::CHANGED_IN_4_0_0, true)) {
                continue;
            }
            $modifiers = EquivalenceRecorder::modifiers(0.0);

            $actual = $this->recorder()->counterfactual(app(LevelRepository::class)->get($levelId), $modifiers, $this->strategy('planner', $modifiers), 1, (int) $decision);

            $this->assertSame($expected, $actual, $key);
        }
    }
}
