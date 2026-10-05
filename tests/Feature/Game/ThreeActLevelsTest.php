<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use Tests\TestCase;

/**
 * P10-2：第 1、2 關的三幕。幕次只用回合門檻，所以靜態預告表必須和實際對局逐回合一致。
 */
class ThreeActLevelsTest extends TestCase
{
    use PlaysCards;

    private const ACTS = [
        'empty-cup' => ['trial-cup', 'cut-supply', 'empty-cup-quiz'],
        'noon-fold' => ['single-shield', 'shift-guard', 'crossed-windows'],
    ];

    private function neutral(): ScenarioModifiers
    {
        $reasons = array_fill_keys(Element::values(), ['code' => 'test', 'message' => '', 'inputs' => []]);

        return new ScenarioModifiers(array_fill_keys(Element::values(), 0.0), $reasons);
    }

    public function test_a_planner_run_reaches_all_three_acts_at_turns_three_and_five(): void
    {
        foreach (self::ACTS as $levelId => $acts) {
            $result = (new BattleSimulator(app(BattleEngine::class)))->run(
                $this->level($levelId),
                $this->neutral(),
                new PlannerStrategy($this->neutral(), app(CardCatalog::class)),
                1,
                'w0h0l0',
            );

            $this->assertSame([
                ['turn' => 2, 'from' => $acts[0], 'to' => $acts[1], 'reason_code' => 'level_phase_turn_gte'],
                ['turn' => 4, 'from' => $acts[1], 'to' => $acts[2], 'reason_code' => 'level_phase_turn_gte'],
            ], $result->levelPhaseChanges, $levelId);
        }
    }

    public function test_every_turn_of_a_live_run_shows_the_intent_the_static_forecast_promised(): void
    {
        foreach (array_keys(self::ACTS) as $levelId) {
            $level = $this->level($levelId);
            $state = $this->startState($level);

            while (! $state->outcome->isFinished()) {
                $scheduled = $level->scheduledPhaseForTurn($state->turn);

                $this->assertSame($scheduled->id, $state->levelPhaseId ?? $level->firstPhase()->id, "{$levelId} turn {$state->turn}");
                $this->assertSame($level->intentForTurn($state->turn, null, $scheduled->id)->toArray(), $state->intent->toArray(), "{$levelId} turn {$state->turn}");

                [$state] = $this->act($state, 'gather', $level);
            }
        }
    }

    public function test_the_first_act_of_empty_cup_only_reinforces_before_the_repair_windows(): void
    {
        $level = $this->level('empty-cup');
        $types = array_map(fn (int $turn): string => $level->intentForTurn($turn, null, $level->scheduledPhaseForTurn($turn)->id)->type, range(1, $level->maxTurns));

        $this->assertSame(['reinforce', 'reinforce', 'repair', 'reinforce', 'reinforce', 'repair', 'reinforce', 'reinforce'], $types);
        $this->assertLessThan(
            $level->intentForTurn(6, null, 'empty-cup-quiz')->magnitude,
            $level->intentForTurn(3, null, 'cut-supply')->magnitude,
        );
    }

    public function test_the_noon_fold_demo_shield_cannot_be_interrupted_but_the_rotating_shields_can(): void
    {
        $level = $this->level('noon-fold');
        $state = $this->startState($level);
        [$state] = $this->act($state, 'gather', $level);

        // 第一幕：熱盾只能繞，不能用熱系擾序取消。
        [$state, $demo] = $this->act($state, 'disrupt.heat', $level);
        $this->assertContains('interrupt_failed', array_column(array_map(fn ($event): array => $event->toArray(), $demo), 'type'));
        $this->assertSame(['element' => 'heat'], array_intersect_key($state->shields[0], ['element' => 1]));

        // 第二幕：第 3 回合的水盾可以用水系擾序取消。
        $this->assertSame('shift-guard', $state->levelPhaseId);
        [$state, $rotating] = $this->act($state, 'disrupt.water', $level);
        $types = array_column(array_map(fn ($event): array => $event->toArray(), $rotating), 'type');
        $this->assertContains('interrupt', $types);
        $this->assertNotContains('city_shield', $types);
    }
}
