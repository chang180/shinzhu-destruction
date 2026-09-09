<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
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
 * 把平衡驗收釘成測試，讓後續調數值時不會安靜地破壞它。
 *
 * 完整矩陣用 `php artisan game:simulate` 產生；這裡跑的是同一批策略、
 * 同樣的固定 seed，只是斷言驗收門檻。
 *
 * 只對 `available` 的關卡斷言。第 2～5 關的回合數與防線是 P05 的起點，還沒有
 * 依模擬訂定——對還沒平衡的數值斷言 70% 勝率，等於把「尚未驗證」寫成「已通過」。
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

    /**
     * @return list<string>
     */
    private function levelIds(): array
    {
        return array_keys(array_filter(
            app(LevelRepository::class)->all(),
            static fn ($level): bool => $level->available,
        ));
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
        foreach ($this->levelIds() as $levelId) {
            foreach ($this->scenarios() as $label => $modifiers) {
                $rate = $this->winRate($levelId, $modifiers, new PlannerStrategy($modifiers, app(CardCatalog::class)), $label);

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
        foreach ($this->levelIds() as $levelId) {
            foreach ($this->scenarios() as $label => $modifiers) {
                $planner = $this->winRate($levelId, $modifiers, new PlannerStrategy($modifiers, app(CardCatalog::class)), $label);
                $random = $this->winRate($levelId, $modifiers, new RandomStrategy, $label);

                $this->assertGreaterThanOrEqual(
                    0.25,
                    $planner - $random,
                    "{$levelId}／{$label} 的規劃與隨機差距不足 25 個百分點",
                );
            }
        }
    }

    /**
     * 第 1 關的工作是「教會三系輪替與跨系連攜」，所以那條循環在這裡本來就該有用；
     * 要求它在教學關失敗等於要求教學失效。這裡守的是另一件事：它不能變成**不需要
     * 讀預告也一定贏**的必勝按鈕。
     *
     * 「固定套路不能普遍通關」這條驗收由
     * test_a_fixed_line_of_play_cannot_carry_every_scenario 跨情境驗證；
     * 真正要靠護盾與脈衝懲罰它的是第 2 關之後，門檻在 P05 依模擬訂定。
     */
    public function test_the_legacy_sigil_then_ultimate_loop_is_not_an_automatic_win(): void
    {
        foreach ($this->levelIds() as $levelId) {
            foreach ($this->scenarios() as $label => $modifiers) {
                $rate = $this->winRate($levelId, $modifiers, new LegacyCycleStrategy, $label);

                $this->assertLessThan(
                    0.95,
                    $rate,
                    "舊版三系集印記再放終招的套路在 {$levelId}／{$label} 仍有 ".round($rate * 100).'% 勝率',
                );
            }
        }
    }

    public function test_spamming_one_element_is_punished_on_every_level(): void
    {
        foreach ($this->levelIds() as $levelId) {
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
        foreach ($this->levelIds() as $levelId) {
            $modifiers = $this->modifiers(0.0);

            $greedy = $this->winRate($levelId, $modifiers, new GreedyStrategy($modifiers), 'mid');
            $planner = $this->winRate($levelId, $modifiers, new PlannerStrategy($modifiers, app(CardCatalog::class)), 'mid');

            $this->assertGreaterThanOrEqual(0.70, $greedy, "{$levelId} 只剩一種可行策略");
            $this->assertGreaterThanOrEqual(0.70, $planner, "{$levelId} 只剩一種可行策略");
        }
    }

    public function test_the_two_clearing_strategies_are_not_the_same_line_of_play(): void
    {
        $modifiers = $this->modifiers(0.0);
        $simulator = new BattleSimulator(app(BattleEngine::class));
        $distinct = 0;

        foreach ($this->levelIds() as $levelId) {
            $level = app(LevelRepository::class)->get($levelId);

            $greedy = $simulator->run($level, $modifiers, new GreedyStrategy($modifiers), 1, 'mid');
            $planner = $simulator->run($level, $modifiers, new PlannerStrategy($modifiers, app(CardCatalog::class)), 1, 'mid');

            if (array_column($greedy->actions, 'skill_id') !== array_column($planner->actions, 'skill_id')) {
                $distinct++;
            }
        }

        // 兩種策略若在每一關都打出同一串行動，那就只是同一條路線的兩個名字。
        $this->assertGreaterThan(0, $distinct, '兩種通關策略的行動序列完全相同');
    }

    public function test_levels_whose_balance_is_not_verified_yet_are_marked_unavailable(): void
    {
        $levels = app(LevelRepository::class)->all();

        // P04 只交付第 1 關。其餘四關佔住路線與機制，但不得被當成已平衡的內容。
        $this->assertSame(['empty-cup'], $this->levelIds());
        $this->assertCount(5, $levels);
        $this->assertSame(
            ['main', 'main', 'main', 'advanced', 'advanced'],
            array_values(array_map(static fn ($level): string => $level->tier, $levels)),
        );
    }

    public function test_a_fixed_line_of_play_cannot_carry_every_scenario(): void
    {
        // 手牌把「照順序點選」變成一種需要抽到牌才成立的打法。同一條固定循環
        // 在不同情境的落差就是證據：它不是一條穩定通吃的路線。
        $rates = [];

        foreach ($this->scenarios() as $label => $modifiers) {
            $rates[$label] = $this->winRate('empty-cup', $modifiers, new LegacyCycleStrategy, $label);
        }

        $this->assertGreaterThan(0.30, max($rates) - min($rates), '固定循環在三種情境下表現一致，代表資料沒有影響打法');
    }

    public function test_no_strategy_ever_picks_an_action_the_engine_rejects(): void
    {
        $modifiers = $this->modifiers(0.0);
        $simulator = new BattleSimulator(app(BattleEngine::class));

        foreach ($this->levelIds() as $levelId) {
            $level = app(LevelRepository::class)->get($levelId);

            foreach ([new RandomStrategy, new LegacyCycleStrategy, new PlannerStrategy($modifiers, app(CardCatalog::class))] as $strategy) {
                for ($seed = 1; $seed <= 5; $seed++) {
                    $result = $simulator->run($level, $modifiers, $strategy, $seed, 'mid');

                    $this->assertSame(0, $result->rejectedActions, "{$strategy->name()} 在 {$levelId} 選了非法行動");
                }
            }
        }
    }
}
