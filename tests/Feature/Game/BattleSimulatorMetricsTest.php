<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Simulation\Strategies\RandomStrategy;
use Tests\TestCase;

class BattleSimulatorMetricsTest extends TestCase
{
    private function neutral(): ScenarioModifiers
    {
        $reasons = array_fill_keys(Element::values(), ['code' => 'test', 'message' => '', 'inputs' => []]);

        return new ScenarioModifiers(array_fill_keys(Element::values(), 0.0), $reasons);
    }

    public function test_uninterrupted_repairs_are_counted_with_their_scheduled_amounts(): void
    {
        // RandomStrategy 不會刻意打斷；找一局兩次修復（14、16）都落地的 seed。
        $level = app(LevelRepository::class)->get('empty-cup');
        $simulator = new BattleSimulator(app(BattleEngine::class));
        $found = null;

        for ($seed = 1; $seed <= 50 && $found === null; $seed++) {
            $result = $simulator->run($level, $this->neutral(), new RandomStrategy, $seed, 'mid');

            if ($result->metrics['city_repairs'] === 2) {
                $found = $result;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame(0, $found->metrics['repairs_interrupted']);
        $this->assertLessThanOrEqual(30, $found->metrics['city_repair_amount']);
        $this->assertGreaterThan(0, $found->metrics['city_repair_amount']);
    }

    public function test_overhaul_start_is_recorded_as_a_phase_change(): void
    {
        $level = app(LevelRepository::class)->get('stored-night');
        $simulator = new BattleSimulator(app(BattleEngine::class));

        $result = $simulator->run($level, $this->neutral(), new PlannerStrategy($this->neutral(), app(CardCatalog::class)), 1, 'mid');

        $this->assertSame(1, $result->metrics['overhaul_started']);
        $this->assertSame('standby', $result->phaseChanges[0]['from']);
        $this->assertSame('overhaul', $result->phaseChanges[0]['to']);
        $this->assertSame('overhaul_started', $result->phaseChanges[0]['reason_code']);
    }

    public function test_reward_deck_composition_is_used_and_labelled(): void
    {
        $level = app(LevelRepository::class)->get('meter-feast');
        $deck = $level->deck;
        $deck['long-flow']--;
        $deck['tide-siege'] = 1;

        $simulator = new BattleSimulator(app(BattleEngine::class));
        $played = [];

        foreach ([1, 2, 3] as $seed) {
            $result = $simulator->run($level, $this->neutral(), new PlannerStrategy($this->neutral(), app(CardCatalog::class)), $seed, 'mid', $deck, 'tide-siege');
            $played = [...$played, ...array_column($result->actions, 'card_id')];
        }

        $this->assertSame('tide-siege', $result->deck);
        $this->assertContains('tide-siege-1', $played);
        $this->assertNotContains('long-flow-3', $played);
    }
}
