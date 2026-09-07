<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Element;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\GreedyStrategy;
use App\Domain\Game\Simulation\Strategies\LegacyCycleStrategy;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Simulation\Strategies\RandomStrategy;
use App\Domain\Game\Simulation\Strategies\SingleElementStrategy;
use App\Domain\Game\Simulation\Strategy;
use Tests\TestCase;

/**
 * 把 P03 的平衡驗收釘成測試，讓後續調數值時不會安靜地破壞它。
 *
 * 完整矩陣用 `php artisan game:simulate` 產生；這裡跑的是同一批策略、
 * 同樣的固定 seed，只是斷言驗收門檻。
 */
class StrategyMatrixTest extends TestCase
{
    private const SEEDS = 25;

    private function modifiers(float $value): ScenarioModifiers
    {
        $modifiers = [];
        $reasons = [];

        foreach (Element::all() as $element) {
            $modifiers[$element->value] = $value;
            $reasons[$element->value] = ['code' => 'test', 'message' => '', 'inputs' => []];
        }

        return new ScenarioModifiers($modifiers, $reasons);
    }

    /**
     * @return array<string, ScenarioModifiers>
     */
    private function scenarios(): array
    {
        $limit = (float) config('game.scenario.modifier_limit');

        return ['low' => $this->modifiers(-$limit), 'mid' => $this->modifiers(0.0), 'high' => $this->modifiers($limit)];
    }

    private function winRate(string $levelId, ScenarioModifiers $modifiers, Strategy $strategy, string $label): float
    {
        $level = app(LevelRepository::class)->get($levelId);
        $simulator = new BattleSimulator(app(BattleEngine::class));
        $wins = 0;

        for ($seed = 1; $seed <= self::SEEDS; $seed++) {
            $wins += $simulator->run($level, $modifiers, $strategy, $seed, $label)->won() ? 1 : 0;
        }

        return $wins / self::SEEDS;
    }

    public function test_the_planning_strategy_clears_every_level_and_scenario(): void
    {
        foreach (app(LevelRepository::class)->ids() as $levelId) {
            foreach ($this->scenarios() as $label => $modifiers) {
                $rate = $this->winRate($levelId, $modifiers, new PlannerStrategy($modifiers), $label);

                $this->assertGreaterThanOrEqual(
                    0.70,
                    $rate,
                    "規劃策略在 {$levelId}／{$label} 只有 ".round($rate * 100).'% 勝率',
                );
            }
        }
    }

    public function test_the_planning_strategy_beats_random_by_at_least_25_points(): void
    {
        foreach (app(LevelRepository::class)->ids() as $levelId) {
            foreach ($this->scenarios() as $label => $modifiers) {
                $planner = $this->winRate($levelId, $modifiers, new PlannerStrategy($modifiers), $label);
                $random = $this->winRate($levelId, $modifiers, new RandomStrategy, $label);

                $this->assertGreaterThanOrEqual(
                    0.25,
                    $planner - $random,
                    "{$levelId}／{$label} 的規劃與隨機差距不足 25 個百分點",
                );
            }
        }
    }

    public function test_the_legacy_sigil_then_ultimate_loop_no_longer_guarantees_a_win(): void
    {
        foreach (app(LevelRepository::class)->ids() as $levelId) {
            foreach ($this->scenarios() as $label => $modifiers) {
                $rate = $this->winRate($levelId, $modifiers, new LegacyCycleStrategy, $label);

                $this->assertLessThan(
                    0.80,
                    $rate,
                    "舊版三系集印記再放終招的套路在 {$levelId}／{$label} 仍有 ".round($rate * 100).'% 勝率',
                );
            }
        }
    }

    public function test_spamming_one_element_is_punished_on_every_level(): void
    {
        foreach (app(LevelRepository::class)->ids() as $levelId) {
            foreach ($this->scenarios() as $label => $modifiers) {
                $rate = $this->winRate($levelId, $modifiers, new SingleElementStrategy(Element::Water), $label);

                $this->assertLessThan(
                    0.80,
                    $rate,
                    "單系連按在 {$levelId}／{$label} 仍有 ".round($rate * 100).'% 勝率',
                );
            }
        }
    }

    public function test_at_least_two_different_strategies_can_clear_each_level(): void
    {
        foreach (app(LevelRepository::class)->ids() as $levelId) {
            $modifiers = $this->modifiers(0.0);

            $greedy = $this->winRate($levelId, $modifiers, new GreedyStrategy($modifiers), 'mid');
            $planner = $this->winRate($levelId, $modifiers, new PlannerStrategy($modifiers), 'mid');

            $this->assertGreaterThanOrEqual(0.70, $greedy, "{$levelId} 只剩一種可行策略");
            $this->assertGreaterThanOrEqual(0.70, $planner, "{$levelId} 只剩一種可行策略");
        }
    }

    public function test_the_two_clearing_strategies_are_not_the_same_line_of_play(): void
    {
        $modifiers = $this->modifiers(0.0);
        $simulator = new BattleSimulator(app(BattleEngine::class));
        $distinct = 0;

        foreach (app(LevelRepository::class)->ids() as $levelId) {
            $level = app(LevelRepository::class)->get($levelId);

            $greedy = $simulator->run($level, $modifiers, new GreedyStrategy($modifiers), 1, 'mid');
            $planner = $simulator->run($level, $modifiers, new PlannerStrategy($modifiers), 1, 'mid');

            if (array_column($greedy->actions, 'skill_id') !== array_column($planner->actions, 'skill_id')) {
                $distinct++;
            }
        }

        // 兩種策略若在每一關都打出同一串行動，那就只是同一條路線的兩個名字。
        $this->assertGreaterThan(0, $distinct, '兩種通關策略的行動序列完全相同');
    }

    public function test_no_strategy_ever_picks_an_action_the_engine_rejects(): void
    {
        $modifiers = $this->modifiers(0.0);
        $simulator = new BattleSimulator(app(BattleEngine::class));

        foreach (app(LevelRepository::class)->ids() as $levelId) {
            $level = app(LevelRepository::class)->get($levelId);

            foreach ([new RandomStrategy, new LegacyCycleStrategy, new PlannerStrategy($modifiers)] as $strategy) {
                for ($seed = 1; $seed <= 5; $seed++) {
                    $result = $simulator->run($level, $modifiers, $strategy, $seed, 'mid');

                    $this->assertSame(0, $result->rejectedActions, "{$strategy->name()} 在 {$levelId} 選了非法行動");
                }
            }
        }
    }
}
