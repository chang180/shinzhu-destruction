<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\SimulationResult;
use App\Domain\Game\Simulation\Strategies\PlannerOneMistakeStrategy;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use Tests\TestCase;

/**
 * P10-2.1 的失誤語意：只承認本關核心機制上明確可辨識的錯誤處理，
 * 不把 planner 的次佳合法行動當失誤。
 */
class PlannerOneMistakeStrategyTest extends TestCase
{
    private function neutral(): ScenarioModifiers
    {
        $reasons = array_fill_keys(Element::values(), ['code' => 'test', 'message' => '', 'inputs' => []]);

        return new ScenarioModifiers(array_fill_keys(Element::values(), 0.0), $reasons);
    }

    private function strategy(): PlannerOneMistakeStrategy
    {
        return new PlannerOneMistakeStrategy($this->neutral(), app(CardCatalog::class));
    }

    private function planner(): PlannerStrategy
    {
        return new PlannerStrategy($this->neutral(), app(CardCatalog::class));
    }

    /**
     * @param  array<string, int>|null  $deck
     */
    private function play(string $levelId, PlannerOneMistakeStrategy|PlannerStrategy $strategy, int $seed, ?array $deck = null): SimulationResult
    {
        return (new BattleSimulator(app(BattleEngine::class)))
            ->run(app(LevelRepository::class)->get($levelId), $this->neutral(), $strategy, $seed, 'w0h0l0', $deck);
    }

    public function test_attacking_into_a_same_element_shield_when_a_bypass_exists_is_a_mechanic_mistake(): void
    {
        // noon-fold seed 1 第 3 回合：第一幕的熱盾（16）還站著，planner 最佳是打水系繞過它。
        $report = $this->play('noon-fold', $this->strategy(), 1)->strategyReport;

        $this->assertSame(PlannerOneMistakeStrategy::STATUS_INJECTED, $report['status']);
        $this->assertSame(PlannerOneMistakeStrategy::KIND_WALKED_INTO_SHIELD, $report['mistake_kind']);
        $this->assertSame(3, $report['turn']);
        $this->assertSame('probe.water', $report['best']['skill_id']);
        $this->assertSame('breach.heat', $report['mistake']['skill_id']);
        $this->assertSame(['heat'], $report['context']['shielded_elements']);
        $this->assertSame(16, $report['context']['shield_amount']);
    }

    public function test_switching_element_to_bypass_a_shield_is_never_the_mistake(): void
    {
        /*
         * 同一局：擾序、換系進攻都是合理打法，不能被標成失誤。
         * 唯一算失誤的是撞上那道熱盾。
         */
        $report = $this->play('noon-fold', $this->strategy(), 1)->strategyReport;
        $shielded = $report['context']['shielded_elements'];

        $mistakeElement = str_contains($report['mistake']['skill_id'], '.')
            ? explode('.', $report['mistake']['skill_id'])[1]
            : null;

        $this->assertContains($mistakeElement, $shielded, '失誤必須是撞上站著的同系護盾');
        $this->assertNotSame('probe.water', $report['mistake']['skill_id']);
        $this->assertNotSame('breach.water', $report['mistake']['skill_id']);
    }

    public function test_a_correct_interrupt_is_never_labelled_the_mistake(): void
    {
        /*
         * P10-2（p10-di-2）在這一局把 disrupt.water 標成失誤——那正是打斷可打斷水盾的
         * 正確打法。新定義下它不可能是失誤。
         */
        $report = $this->play('noon-fold', $this->strategy(), 1)->strategyReport;

        $this->assertNotSame('disrupt.water', $report['mistake']['skill_id']);
    }

    public function test_ignoring_an_interruptible_repair_the_player_could_stop_is_a_mechanic_mistake(): void
    {
        // empty-cup seed 1 第 3 回合（斷補幕）：可打斷的水系修復 19，planner 最佳就是去打斷。
        $report = $this->play('empty-cup', $this->strategy(), 1)->strategyReport;

        $this->assertSame(PlannerOneMistakeStrategy::STATUS_INJECTED, $report['status']);
        $this->assertSame(PlannerOneMistakeStrategy::KIND_MISSED_INTERRUPT, $report['mistake_kind']);
        $this->assertSame(3, $report['turn']);
        $this->assertSame('disrupt.water', $report['best']['skill_id']);
        $this->assertSame('repair', $report['context']['intent_type']);
        $this->assertSame('water', $report['context']['intent_element']);
        $this->assertSame(19, $report['context']['intent_magnitude']);

        // 失誤必須是「不處理這個預告」，而不是另一張同樣能打斷的牌。
        $this->assertNotSame('disrupt.water', $report['mistake']['skill_id']);
    }

    public function test_no_eligible_mistake_when_the_hand_can_never_make_a_clear_mechanic_error(): void
    {
        /*
         * 牌組裡沒有水系擾序，所以 planner 永遠不可能「本來要打斷」；
         * 第 1 關也沒有護盾。整局都沒有明確的機制錯誤可以注入。
         */
        $deck = ['long-flow' => 5, 'open-chill' => 5, 'trample-green' => 5];

        $mistake = $this->play('empty-cup', $this->strategy(), 1, $deck);

        $this->assertSame(PlannerOneMistakeStrategy::STATUS_NO_ELIGIBLE, $mistake->strategyReport['status']);
        $this->assertNull($mistake->strategyReport['turn']);
        $this->assertNull($mistake->strategyReport['mistake_kind']);
        $this->assertNull($mistake->strategyReport['mistake']);
    }

    public function test_a_game_without_an_eligible_mistake_plays_exactly_like_the_planner(): void
    {
        $deck = ['long-flow' => 5, 'open-chill' => 5, 'trample-green' => 5];

        $mistake = $this->play('empty-cup', $this->strategy(), 1, $deck);
        $planner = $this->play('empty-cup', $this->planner(), 1, $deck);

        $this->assertSame(PlannerOneMistakeStrategy::STATUS_NO_ELIGIBLE, $mistake->strategyReport['status']);
        $this->assertSame($planner->actions, $mistake->actions);
        $this->assertSame($planner->outcome, $mistake->outcome);
        $this->assertSame($planner->coreRemaining, $mistake->coreRemaining);
    }

    public function test_the_same_level_deck_scenario_and_seed_reproduce_the_same_mistake(): void
    {
        foreach (['empty-cup', 'noon-fold'] as $levelId) {
            $first = $this->play($levelId, $this->strategy(), 7);
            $second = $this->play($levelId, $this->strategy(), 7);

            $this->assertSame($first->strategyReport, $second->strategyReport, $levelId);
            $this->assertSame($first->actions, $second->actions, $levelId);
            $this->assertSame($first->outcome, $second->outcome, $levelId);
            $this->assertSame($first->coreRemaining, $second->coreRemaining, $levelId);
        }
    }

    public function test_injects_exactly_one_mistake_per_game_and_resets_between_seeds(): void
    {
        $strategy = $this->strategy();

        foreach ([1, 2, 3] as $seed) {
            $mistake = $this->play('noon-fold', $strategy, $seed);
            $baseline = $this->play('noon-fold', $this->planner(), $seed);
            $report = $mistake->strategyReport;

            $this->assertSame(PlannerOneMistakeStrategy::STATUS_INJECTED, $report['status'], "seed {$seed}");

            $index = $this->indexOfTurn($mistake->actions, $report['turn']);

            // 失誤之前和 planner 一模一樣，失誤那一手不同。
            $this->assertSame(
                array_slice($baseline->actions, 0, $index),
                array_slice($mistake->actions, 0, $index),
                "seed {$seed}"
            );
            $this->assertSame($report['mistake']['skill_id'], $mistake->actions[$index]['skill_id'], "seed {$seed}");
            $this->assertNotSame($baseline->actions[$index]['skill_id'], $mistake->actions[$index]['skill_id'], "seed {$seed}");

            // 之後不再注入第二次。
            $this->assertCount(1, array_filter(
                [$report],
                static fn (array $row): bool => $row['status'] === PlannerOneMistakeStrategy::STATUS_INJECTED,
            ));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function indexOfTurn(array $actions, int $turn): int
    {
        foreach ($actions as $index => $action) {
            if ($action['turn'] === $turn) {
                return $index;
            }
        }

        $this->fail("找不到第 {$turn} 回合的行動");
    }
}
