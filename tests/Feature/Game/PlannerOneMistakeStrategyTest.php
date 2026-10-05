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

    /**
     * @return list<string|null>
     */
    private function skills(SimulationResult $result): array
    {
        return array_map(static fn (array $action): ?string => $action['card_id'] ?? $action['skill_id'], $result->actions);
    }

    public function test_deviates_from_the_planner_once_at_the_first_interruptible_forecast(): void
    {
        // empty-cup 第 3 回合是第一個可打斷的修復預告。
        $level = app(LevelRepository::class)->get('empty-cup');
        $simulator = new BattleSimulator(app(BattleEngine::class));
        $mistake = new PlannerOneMistakeStrategy($this->neutral(), app(CardCatalog::class));
        $planner = new PlannerStrategy($this->neutral(), app(CardCatalog::class));

        foreach ([1, 2] as $seed) {
            $expected = $this->skills($simulator->run($level, $this->neutral(), $planner, $seed, 'mid'));
            $actual = $this->skills($simulator->run($level, $this->neutral(), $mistake, $seed, 'mid'));

            $this->assertSame(array_slice($expected, 0, 2), array_slice($actual, 0, 2), "seed {$seed}");
            $this->assertNotSame($expected[2], $actual[2], "seed {$seed}");
        }
    }
}
