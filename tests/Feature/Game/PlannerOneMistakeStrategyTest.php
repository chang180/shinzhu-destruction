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

    /**
     * @param  array<string, int>|null  $deck
     */
    private function play(string $levelId, PlannerOneMistakeStrategy|PlannerStrategy $strategy, int $seed, ?array $deck = null): SimulationResult
    {
        return (new BattleSimulator(app(BattleEngine::class)))
            ->run(app(LevelRepository::class)->get($levelId), $this->neutral(), $strategy, $seed, 'w0h0l0', $deck);
    }

    public function test_another_copy_of_the_best_card_is_not_counted_as_a_mistake(): void
    {
        // noon-fold seed 17 第 2 回合：最佳是 open-chill-1，排名第二是同招的 open-chill-2。
        $report = $this->play('noon-fold', $this->strategy(), 17)->strategyReport;

        $this->assertSame('open-chill-2', $report['legacy_second']['card_id']);
        $this->assertTrue($report['legacy_second_same_semantics']);
        $this->assertSame(PlannerOneMistakeStrategy::STATUS_INJECTED, $report['status']);
        $this->assertNotSame(PlannerOneMistakeStrategy::signature($report['best']), PlannerOneMistakeStrategy::signature($report['mistake']));
        $this->assertSame('probe.water', $report['mistake']['skill_id']);
    }

    public function test_an_action_tied_with_the_best_score_is_not_a_strictly_worse_mistake(): void
    {
        // empty-cup seed 6 第 3 回合：breach.land 與 breach.heat 同分，真正的失誤要往下找。
        $report = $this->play('empty-cup', $this->strategy(), 6)->strategyReport;

        $this->assertTrue($report['legacy_second_tied']);
        $this->assertFalse($report['legacy_second_same_semantics']);
        $this->assertSame('breach.land', $report['best']['skill_id']);
        $this->assertSame('probe.heat', $report['mistake']['skill_id']);
        $this->assertSame(9.25, $report['score_delta']);
    }

    public function test_a_window_with_only_one_legal_play_is_marked_no_eligible_mistake_and_follows_the_planner(): void
    {
        // 全是水系破陣：第 3 回合（第一個可打斷預告）破陣還在冷卻，合法出牌只剩蓄勢。
        $deck = ['spend-tide' => 15];

        $mistake = $this->play('empty-cup', $this->strategy(), 1, $deck);
        $planner = $this->play('empty-cup', new PlannerStrategy($this->neutral(), app(CardCatalog::class)), 1, $deck);

        $this->assertSame(PlannerOneMistakeStrategy::STATUS_NO_ELIGIBLE, $mistake->strategyReport['status']);
        $this->assertSame(3, $mistake->strategyReport['turn']);
        $this->assertNull($mistake->strategyReport['mistake']);
        $this->assertSame($planner->actions, $mistake->actions);
    }

    public function test_injects_exactly_one_mistake_per_game_and_resets_between_seeds(): void
    {
        $strategy = $this->strategy();
        $planner = new PlannerStrategy($this->neutral(), app(CardCatalog::class));

        foreach ([1, 2, 3] as $seed) {
            $mistake = $this->play('empty-cup', $strategy, $seed);
            $baseline = $this->play('empty-cup', $planner, $seed);
            $turn = $mistake->strategyReport['turn'];
            $index = $turn - 1;

            $this->assertSame(PlannerOneMistakeStrategy::STATUS_INJECTED, $mistake->strategyReport['status'], "seed {$seed}");
            $this->assertSame(array_slice($baseline->actions, 0, $index), array_slice($mistake->actions, 0, $index), "seed {$seed}");
            $this->assertSame($mistake->strategyReport['mistake']['skill_id'], $mistake->actions[$index]['skill_id'], "seed {$seed}");
            $this->assertNotSame($baseline->actions[$index]['skill_id'], $mistake->actions[$index]['skill_id'], "seed {$seed}");
        }
    }
}
