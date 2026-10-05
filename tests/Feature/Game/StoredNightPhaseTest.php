<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleState;
use App\Domain\Game\CityIntent;
use App\Domain\Game\Element;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Outcome;
use Tests\TestCase;

class StoredNightPhaseTest extends TestCase
{
    use PlaysCards;

    private function countdown(): BattleState
    {
        $level = $this->level('stored-night');
        $state = $this->startState($level);
        $state->coreResilience = 35;
        [$state] = $this->act($state, 'gather', $level);

        return $state;
    }

    public function test_threshold_starts_two_full_windows_after_the_shown_city_response(): void
    {
        $level = $this->level('stored-night');
        $state = $this->startState($level);
        $state->coreResilience = 35;
        $shown = $state->intent->toArray();

        [$next, $events] = $this->act($state, 'gather', $level);

        $this->assertSame('county-alert', $state->levelPhaseId);
        $this->assertSame($shown, $state->intent->toArray());
        $this->assertSame('overhaul-warning', $next->levelPhaseId);
        $this->assertSame('overhaul', $next->phase);
        $this->assertSame(3, $next->flags['overhaul_due_turn']);
        $this->assertSame(Element::Land, $next->intent->element);
        $this->assertSame(35, $next->coreResilience);
        $this->assertContains('city_shield', array_column($events, 'type'));
        $this->assertContains('overhaul_started', array_column($events, 'reasonCode'));
    }

    public function test_above_threshold_enters_warning_on_turn_four_without_starting_overhaul(): void
    {
        $level = $this->level('stored-night');
        $state = $this->startState($level);
        for ($turn = 1; $turn <= 3; $turn++) {
            [$state] = $this->act($state, 'gather', $level);
        }

        $this->assertSame(4, $state->turn);
        $this->assertSame('overhaul-warning', $state->levelPhaseId);
        $this->assertSame('standby', $state->phase);
        $this->assertArrayNotHasKey('overhaul_started', $state->flags);
    }

    public function test_crossing_threshold_while_interrupting_an_ordinary_intent_still_starts_overhaul(): void
    {
        $level = $this->level('stored-night');
        $state = $this->startState($level);
        $state->turn = 2;
        $state->coreResilience = 36;
        $state->intent = $level->intentForTurn(2);

        [$next] = $this->act($state, 'disrupt.heat', $level);

        $this->assertTrue($next->flags['overhaul_started']);
        $this->assertSame('overhaul', $next->phase);
        $this->assertSame(4, $next->flags['overhaul_due_turn']);
        $this->assertSame([], $next->shields);
    }

    public function test_warning_reinforcement_rearms_the_water_defense_unless_interrupted(): void
    {
        $level = $this->level('stored-night');
        $state = $this->startState($level);
        $state->turn = 4;
        $state->levelPhaseId = 'overhaul-warning';
        $state->intent = $level->intentForTurn(4, null, 'overhaul-warning');
        $state->defenses['water'] = 0;
        $state->breachedElements['water'] = true;

        [$reinforced, $events] = $this->act($state, 'gather', $level);
        [$interrupted] = $this->act($state, 'disrupt.water', $level);

        $this->assertSame(22, $reinforced->defense(Element::Water));
        $this->assertArrayNotHasKey('water', $reinforced->breachedElements);
        $this->assertContains('city_reinforce', array_column($events, 'type'));
        $this->assertSame(0, $interrupted->defense(Element::Water));
        $this->assertTrue($interrupted->breachedElements['water']);
    }

    public function test_distinct_interruptions_stop_and_open_breach_for_every_element(): void
    {
        $level = $this->level('stored-night');
        [$first] = $this->act($this->countdown(), 'disrupt.land', $level);
        $this->assertSame(['land'], $first->flags['overhaul_interrupt_elements']);
        $this->assertSame('overhaul', $first->phase);

        [$stopped, $events] = $this->act($first, 'disrupt.water', $level);

        $this->assertTrue($stopped->flags['overhaul_stopped']);
        $this->assertArrayNotHasKey('overhaul_completed', $stopped->flags);
        $this->assertSame('standby', $stopped->phase);
        $this->assertSame('last-night', $stopped->levelPhaseId);
        $this->assertTrue($stopped->breachAvailable);
        $this->assertSame([], $stopped->breachedElements);
        $this->assertNotContains('city_repair', array_column($events, 'type'));
        foreach (Element::all() as $element) {
            [$hit, $hitEvents] = $this->act($stopped, 'probe.'.$element->value, $level);
            $this->assertFalse($hit->breachAvailable);
            $this->assertContains('cue.breach.consumed', array_column($hitEvents, 'cueId'));
        }
    }

    public function test_repeating_land_on_the_water_window_cannot_stop_or_delay_completion(): void
    {
        $level = $this->level('stored-night');
        [$first] = $this->act($this->countdown(), 'disrupt.land', $level);
        $first->cooldowns = [];

        [$completed, $events] = $this->act($first, 'disrupt.land', $level);

        $this->assertTrue($completed->flags['overhaul_completed']);
        $this->assertArrayNotHasKey('overhaul_stopped', $completed->flags);
        $this->assertSame('last-night', $completed->levelPhaseId);
        $this->assertContains('interrupt_failed', array_column($events, 'type'));
        $this->assertContains('city_repair', array_column($events, 'type'));
    }

    public function test_repeated_matching_interruptions_count_as_only_one_element(): void
    {
        $definition = config('game.levels.stored-night');
        $definition['overhaul']['countdown_turns'] = 3;
        $level = LevelDefinition::fromConfig('stored-night', $definition);
        $state = $this->startState($level);
        $state->coreResilience = 35;
        [$state] = $this->act($state, 'gather', $level);
        [$state] = $this->act($state, 'disrupt.land', $level);
        $state->cooldowns = [];

        [$next] = $this->act($state, 'disrupt.land', $level);

        $this->assertSame(['land'], $next->flags['overhaul_interrupt_elements']);
        $this->assertSame('overhaul', $next->phase);
        $this->assertArrayNotHasKey('overhaul_stopped', $next->flags);
    }

    public function test_stopped_overhaul_never_restarts_in_the_last_act(): void
    {
        $level = $this->level('stored-night');
        [$first] = $this->act($this->countdown(), 'disrupt.land', $level);
        [$stopped] = $this->act($first, 'disrupt.water', $level);
        $stopped->coreResilience = 1;

        [$next, $events] = $this->act($stopped, 'gather', $level);

        $this->assertSame('last-night', $next->levelPhaseId);
        $this->assertSame('standby', $next->phase);
        $this->assertNotContains('overhaul_started', array_column($events, 'reasonCode'));
        $this->assertTrue($next->flags['overhaul_stopped']);
    }

    public function test_only_interrupting_the_due_water_window_does_not_postpone_repair(): void
    {
        $level = $this->level('stored-night');
        [$first] = $this->act($this->countdown(), 'gather', $level);

        [$completed, $events] = $this->act($first, 'disrupt.water', $level);

        $this->assertTrue($completed->flags['overhaul_completed']);
        $this->assertSame('standby', $completed->phase);
        $this->assertSame('last-night', $completed->levelPhaseId);
        $this->assertContains('overhaul_interrupt_recorded', array_column($events, 'reasonCode'));
        $this->assertContains('city_repair', array_column($events, 'type'));
    }

    public function test_completion_repairs_once_and_never_restarts_after_core_drops_again(): void
    {
        $level = $this->level('stored-night');
        [$first] = $this->act($this->countdown(), 'gather', $level);
        $this->assertSame(35, $first->coreResilience);
        [$completed] = $this->act($first, 'gather', $level);
        $this->assertSame(83, $completed->coreResilience);
        $completed->coreResilience = 30;

        [$next, $events] = $this->act($completed, 'gather', $level);

        $this->assertSame('last-night', $next->levelPhaseId);
        $this->assertSame('standby', $next->phase);
        $this->assertNotContains('overhaul_started', array_column($events, 'reasonCode'));
        $this->assertSame(3, $next->flags['overhaul_due_turn']);
    }

    public function test_lethal_attack_before_due_turn_wins_without_repair_or_phase_advance(): void
    {
        $level = $this->level('stored-night');
        $state = $this->countdown();
        $state->coreResilience = 1;

        [$won, $events] = $this->act($state, 'probe.heat', $level);

        $this->assertSame(Outcome::PlayerVictory, $won->outcome);
        $this->assertSame(2, $won->turn);
        $this->assertSame('overhaul-warning', $won->levelPhaseId);
        $this->assertNotContains('city_repair', array_column($events, 'type'));
        $this->assertArrayNotHasKey('overhaul_completed', $won->flags);
    }

    public function test_final_timeout_repairs_then_finishes_without_an_extra_turn(): void
    {
        $level = $this->level('stored-night');
        $state = $this->countdown();
        $state->turn = $level->maxTurns;
        $state->flags['overhaul_due_turn'] = $state->turn;
        $state->intent = new CityIntent('overhaul', Element::Water, 48, true, $state->turn, '重整到期');
        $state = $this->reveal($state, $level);

        $result = $this->apply($state, new ActionRequest('timeout-final', $state->version, ActionType::Timeout), $level);

        $this->assertSame(Outcome::CityHeld, $result->state->outcome);
        $this->assertSame($level->maxTurns, $result->state->turn);
        $this->assertSame(83, $result->state->coreResilience);
        $this->assertTrue($result->state->flags['overhaul_completed']);
        $this->assertNotContains('level_phase_change', array_column($result->events, 'type'));
    }
}
