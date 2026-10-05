<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Simulation\Strategies\RandomStrategy;
use App\Domain\Game\Simulation\Strategy;
use Random\Randomizer;
use Tests\TestCase;

class BattleSimulatorMetricsTest extends TestCase
{
    public function test_ultimate_timing_and_terminal_malice_come_from_the_finished_engine_result(): void
    {
        $level = app(LevelRepository::class)->get('empty-cup');
        $engine = app(BattleEngine::class);
        $state = $engine->start($level, 1);
        $state->turn = 5;
        $state->coreResilience = 1;
        $state->malice = 9;
        $state->sigils = array_fill_keys(Element::values(), 3);
        $state = $engine->apply($state, new ActionRequest('reveal', $state->version, ActionType::Reveal), $level, $this->neutral())->state;
        $strategy = new class implements Strategy
        {
            public function name(): string
            {
                return 'ultimate-fixture';
            }

            public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
            {
                return ['type' => 'play', 'fixed' => 'ultimate', 'skill_id' => 'ultimate'];
            }
        };
        $expected = $engine->apply($state, new ActionRequest('expected', $state->version, ActionType::Play, fixedSkillId: 'ultimate'), $level, $this->neutral());

        $simulator = new BattleSimulator($engine);
        $result = $simulator->continueFrom($state, $level, $this->neutral(), $strategy, 1, 'mid');
        $again = $simulator->continueFrom($state, $level, $this->neutral(), $strategy, 1, 'mid');

        $this->assertTrue($result->won());
        $this->assertSame([5], $result->ultimateTurns);
        $this->assertSame(1, $result->metrics['ultimate_uses']);
        $this->assertSame(3, $expected->state->malice);
        $this->assertSame($expected->state->malice, $result->terminalMalice);
        $this->assertSame(get_object_vars($result), get_object_vars($again));
    }

    public function test_a_game_without_ultimate_keeps_timing_empty_and_records_actual_losing_malice(): void
    {
        $level = app(LevelRepository::class)->get('meter-feast');
        $strategy = new class implements Strategy
        {
            public function name(): string
            {
                return 'gather-fixture';
            }

            public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
            {
                return ['type' => 'play', 'fixed' => 'gather', 'skill_id' => 'gather'];
            }
        };

        $result = (new BattleSimulator(app(BattleEngine::class)))->run($level, $this->neutral(), $strategy, 1, 'mid');

        $this->assertFalse($result->won());
        $this->assertSame([], $result->ultimateTurns);
        $this->assertSame(0, $result->metrics['ultimate_uses']);
        $this->assertSame(10, $result->terminalMalice);
        $this->assertSame(2, $result->metrics['pulse_windows']);
        $this->assertSame(5, $result->metrics['repair_windows']);
    }

    private function neutral(): ScenarioModifiers
    {
        $reasons = array_fill_keys(Element::values(), ['code' => 'test', 'message' => '', 'inputs' => []]);

        return new ScenarioModifiers(array_fill_keys(Element::values(), 0.0), $reasons);
    }

    public function test_uninterrupted_repairs_are_counted_with_their_scheduled_amounts(): void
    {
        // RandomStrategy 不會刻意打斷；找一局兩次修復（第二幕 19、第三幕 20）都落地的 seed。
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
        $this->assertLessThanOrEqual(39, $found->metrics['city_repair_amount']);
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
