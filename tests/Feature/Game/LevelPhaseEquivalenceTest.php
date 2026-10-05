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
 * P10-1 的 3.1.0 錄製保留為歷史證據；P10-5 起五關內容都已改版。
 *
 * 期望值來自 P10-1 動工前錄下的 tests/Fixtures/p10-1/equivalence.json（見 meta.generated_at_commit），
 * 不是由現在的程式算出來的。公開局面只比對當時就存在的欄位。
 *
 * 4.0.0（P10-2）改了第 1、2 關的內容，那兩關的錄製只代表 3.1.0 舊局，改由舊局唯讀／重播測試
 * 保護（CounterfactualTest、LegacyRunCompatibilityTest）；P10-3～P10-5 再改了第 3～5 關。
 */
class LevelPhaseEquivalenceTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private static array $fixture = [];

    private const SCENARIOS = ['low' => -0.15, 'mid' => 0.0, 'high' => 0.15];

    /**
     * P10-2～P10-5 改了內容的關卡；它們的 3.1.0 錄製不再是現行規則的期望值。
     */
    private const CHANGED_SINCE_RECORDING = ['empty-cup', 'noon-fold', 'meter-feast', 'mirror-shade', 'stored-night'];

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

    public function test_all_five_changed_levels_differ_from_the_retained_old_recordings(): void
    {
        $fixture = $this->fixture();

        foreach ($fixture['games'] as $key => $expected) {
            [$levelId, $scenario, $strategy, $seed] = explode('|', $key);

            $modifiers = EquivalenceRecorder::modifiers(self::SCENARIOS[$scenario]);

            $actual = $this->recorder()->record(app(LevelRepository::class)->get($levelId), $modifiers, $this->strategy($strategy, $modifiers), (int) $seed, $fixture['meta']['public_keys']);

            $this->assertNotSame($expected, $actual, $key);
        }
    }

    public function test_current_full_events_differ_from_the_old_rule_recordings(): void
    {
        $fixture = $this->fixture();

        foreach ($fixture['full_events'] as $levelId => $expected) {
            $modifiers = EquivalenceRecorder::modifiers(0.0);

            $actual = $this->recorder()->record(app(LevelRepository::class)->get($levelId), $modifiers, $this->strategy('planner', $modifiers), 1, $fixture['meta']['public_keys'], true);

            $this->assertNotSame($expected, $actual, $levelId);
        }
    }

    public function test_changed_levels_no_longer_reproduce_their_3_1_0_recordings(): void
    {
        $fixture = $this->fixture();

        foreach (self::CHANGED_SINCE_RECORDING as $levelId) {
            $modifiers = EquivalenceRecorder::modifiers(0.0);

            $actual = $this->recorder()->record(app(LevelRepository::class)->get($levelId), $modifiers, $this->strategy('planner', $modifiers), 1, $fixture['meta']['public_keys'], true);

            $this->assertNotSame($fixture['full_events'][$levelId], $actual, $levelId);
        }

        $this->assertSame('3.1.0', $fixture['meta']['rules_version']);
        $this->assertNotSame($fixture['meta']['rules_version'], config('game.rules_version'));
    }

    public function test_old_overhaul_recordings_are_retained_and_current_rules_differ(): void
    {
        $fixture = $this->fixture();

        foreach ($fixture['overhaul'] as $kind => $expected) {
            $modifiers = EquivalenceRecorder::modifiers(self::SCENARIOS[$expected['scenario']]);
            $sample = ['scenario' => $expected['scenario'], 'strategy' => $expected['strategy'], 'seed' => $expected['seed']];

            $actual = $sample + $this->recorder()->record(app(LevelRepository::class)->get('stored-night'), $modifiers, $this->strategy($expected['strategy'], $modifiers), $expected['seed'], $fixture['meta']['public_keys'], true);

            $this->assertNotSame($expected, $actual, $kind);
            $this->assertContains('overhaul_'.match ($kind) {
                'stopped' => 'stopped',
                'completed' => 'completed',
                'started_then_won' => 'started',
            }, array_column($expected['events'], 'reason_code'));
            $this->assertNotContains('level_phase_change', array_column($expected['events'], 'type'));
        }
    }

    public function test_old_save_restores_without_rewriting_but_new_rules_change_its_continuation(): void
    {
        $fixture = $this->fixture()['legacy_save'];
        $modifiers = EquivalenceRecorder::modifiers(0.0);
        $this->assertArrayNotHasKey('level_phase_id', $fixture['state']);

        $restored = BattleState::fromArray($fixture['state']);
        $actual = $this->recorder()->record(app(LevelRepository::class)->get('stored-night'), $modifiers, $this->strategy('planner', $modifiers), 1, $this->fixture()['meta']['public_keys'], false, $restored);

        $this->assertNotSame(array_diff_key($fixture, ['level' => 1, 'seed' => 1, 'state' => 1]), $actual);
        $this->assertSame($fixture['state'], EquivalenceRecorder::pick($restored->toArray(), array_keys($fixture['state'])));
    }

    public function test_stored_night_counterfactuals_differ_from_the_historical_rule_recordings(): void
    {
        foreach ($this->fixture()['counterfactual'] as $key => $expected) {
            [$levelId, $decision] = explode('|', $key);
            if ($levelId !== 'stored-night') {
                continue;
            }

            $modifiers = EquivalenceRecorder::modifiers(0.0);

            $actual = $this->recorder()->counterfactual(app(LevelRepository::class)->get($levelId), $modifiers, $this->strategy('planner', $modifiers), 1, (int) $decision);

            $this->assertNotSame($expected, $actual, $key);
        }
    }
}
