<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleEvent;
use App\Domain\Game\BattleState;
use App\Domain\Game\CityIntent;
use App\Domain\Game\Element;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Outcome;
use App\Domain\Game\Scenario\ScenarioModifiers;
use Tests\TestCase;

class BattleEngineTest extends TestCase
{
    private function engine(): BattleEngine
    {
        return app(BattleEngine::class);
    }

    private function level(string $id = 'empty-cup'): LevelDefinition
    {
        return app(LevelRepository::class)->get($id);
    }

    private function act(BattleState $state, string $skillId, ?Element $target, ?LevelDefinition $level = null): array
    {
        $level ??= $this->level();
        $result = $this->engine()->apply(
            $state,
            new ActionRequest('a'.$state->version, $state->version, $skillId, $target),
            $level,
            ScenarioModifiers::neutral(),
        );

        return [$result->state, $result->events];
    }

    public function test_a_new_run_starts_with_the_levels_defenses_and_a_visible_telegraph(): void
    {
        $state = $this->engine()->start($this->level());

        $this->assertSame(100, $state->coreResilience);
        $this->assertSame($this->level()->defenses, $state->defenses);
        $this->assertSame(1, $state->turn);
        $this->assertNotNull($state->intent);
        $this->assertStringContainsString('第 1 回合結束時', $state->intent->description);
        $this->assertSame(Outcome::InProgress, $state->outcome);
    }

    public function test_damage_uses_the_defense_value_from_before_the_hit(): void
    {
        $state = $this->engine()->start($this->level());
        $defenseBefore = $state->defense(Element::Heat);

        [, $events] = $this->act($state, 'probe.heat', Element::Heat);

        $impact = $this->event($events, 'impact');

        $this->assertSame($defenseBefore, $impact->delta['effective_defense']);
    }

    public function test_adaptive_resistance_is_added_after_the_hit_not_before(): void
    {
        $state = $this->engine()->start($this->level());

        $defenseBefore = $state->defense(Element::Heat);

        // 第一次打熱系：抗性 0，所以有效防線就是原始防線，命中後才開始加層。
        [$state, $events] = $this->act($state, 'probe.heat', Element::Heat);
        $this->assertSame($defenseBefore, $this->event($events, 'impact')->delta['effective_defense']);
        $this->assertSame(0, $state->resistanceLayers(Element::Heat));

        // 第二次同系：這次命中用的是 0 層，命中後才變成 1 層。
        [$state] = $this->act($state, 'probe.heat', Element::Heat);
        $this->assertSame(1, $state->resistanceLayers(Element::Heat));

        [$state] = $this->act($state, 'probe.heat', Element::Heat);
        $this->assertSame(2, $state->resistanceLayers(Element::Heat));
    }

    public function test_switching_element_decays_the_previous_resistance(): void
    {
        $state = $this->engine()->start($this->level());

        [$state] = $this->act($state, 'probe.heat', Element::Heat);
        [$state] = $this->act($state, 'probe.heat', Element::Heat);
        $this->assertSame(1, $state->resistanceLayers(Element::Heat));

        [$state] = $this->act($state, 'probe.land', Element::Land);
        $this->assertSame(0, $state->resistanceLayers(Element::Heat));
        $this->assertSame('land', $state->lastAttackElement);
    }

    public function test_three_different_elements_in_a_row_trigger_the_combo_multiplier(): void
    {
        $state = $this->engine()->start($this->level());

        [$state, $first] = $this->act($state, 'probe.water', Element::Water);
        $this->assertNull($this->maybeEvent($first, 'combo'));

        [$state, $second] = $this->act($state, 'probe.heat', Element::Heat);
        $this->assertNull($this->maybeEvent($second, 'combo'));

        [, $third] = $this->act($state, 'probe.land', Element::Land);
        $this->assertNotNull($this->maybeEvent($third, 'combo'));
    }

    public function test_gather_breaks_the_combo_chain(): void
    {
        $state = $this->engine()->start($this->level());

        [$state] = $this->act($state, 'probe.water', Element::Water);
        [$state] = $this->act($state, 'probe.heat', Element::Heat);
        [$state] = $this->act($state, 'gather', null);
        $this->assertSame([], $state->comboChain);

        [, $events] = $this->act($state, 'probe.land', Element::Land);
        $this->assertNull($this->maybeEvent($events, 'combo'));
    }

    public function test_a_zeroed_defense_opens_a_breach_that_is_consumed_once(): void
    {
        $state = $this->engine()->start($this->level());
        $state->defenses['heat'] = 4;

        [$state, $events] = $this->act($state, 'probe.heat', Element::Heat);
        $this->assertNotNull($this->maybeEvent($events, 'breach_opened'));
        $this->assertTrue($state->breachAvailable);

        [$state, $next] = $this->act($state, 'probe.land', Element::Land);
        $this->assertNotNull($this->maybeEvent($next, 'breach_consumed'));
        $this->assertFalse($state->breachAvailable);

        // 同一條已經為 0 的防線不能再開一次破綻。
        [$state, $again] = $this->act($state, 'probe.heat', Element::Heat);
        $this->assertNull($this->maybeEvent($again, 'breach_opened'));
        $this->assertFalse($state->breachAvailable);
    }

    public function test_disrupt_cancels_only_a_matching_interruptible_telegraph(): void
    {
        $state = $this->engine()->start($this->level());
        // 空杯關第 3 回合是可打斷的水系修復。
        $state->turn = 3;
        $state->intent = $this->level()->intentForTurn(3);
        $core = $state->coreResilience = 60;

        // 系別不符：打斷失敗，城市照樣完成修復。
        [$missed, $events] = $this->act($state, 'disrupt.land', Element::Land);
        $this->assertNotNull($this->maybeEvent($events, 'interrupt_failed'));
        $this->assertNotNull($this->maybeEvent($events, 'city_repair'));

        // 系別相符：修復被取消，核心比打斷失敗時更低。
        [$stopped, $good] = $this->act($state, 'disrupt.water', Element::Water);
        $this->assertNotNull($this->maybeEvent($good, 'interrupt'));
        $this->assertNull($this->maybeEvent($good, 'city_repair'));
        $this->assertLessThan($missed->coreResilience, $stopped->coreResilience);
        $this->assertLessThan($core, $stopped->coreResilience);
    }

    public function test_an_illegal_action_consumes_nothing(): void
    {
        $state = $this->engine()->start($this->level());
        $before = $state->toArray();

        try {
            $this->act($state, 'ultimate', null);
            $this->fail('印記不足時終招應該被拒絕');
        } catch (InvalidActionException $exception) {
            $this->assertSame('sigils_not_ready', $exception->reasonCode);
        }

        $this->assertSame($before, $state->toArray());
    }

    public function test_cooldown_blocks_the_next_two_decision_turns_then_frees_up(): void
    {
        $state = $this->engine()->start($this->level());

        [$state] = $this->act($state, 'breach.water', Element::Water);
        $readyOn = $state->cooldowns['breach.water'];

        // 冷卻 3：第 2、3、4 回合不可用，第 5 回合放行。
        $this->assertSame(5, $readyOn);
        $this->assertFalse($state->skillReady('breach.water'));

        try {
            $this->act($state, 'breach.water', Element::Water);
            $this->fail('冷卻中不應該可以再放');
        } catch (InvalidActionException $exception) {
            $this->assertSame('on_cooldown', $exception->reasonCode);
        }
    }

    public function test_the_run_ends_the_moment_the_core_reaches_zero_without_a_city_repair(): void
    {
        $state = $this->engine()->start($this->level());
        $state->turn = 3;
        $state->intent = $this->level()->intentForTurn(3);
        $state->coreResilience = 4;

        [$after, $events] = $this->act($state, 'probe.heat', Element::Heat);

        $this->assertSame(Outcome::PlayerVictory, $after->outcome);
        $this->assertSame(0, $after->coreResilience);
        // 城市不能在核心歸零之後補血把自己救回來。
        $this->assertNull($this->maybeEvent($events, 'city_repair'));
    }

    public function test_running_out_of_turns_means_the_city_held(): void
    {
        $level = $this->level();
        $state = $this->engine()->start($level);
        $state->turn = $level->maxTurns;
        $state->intent = $level->intentForTurn($level->maxTurns);

        [$after] = $this->act($state, 'gather', null);

        $this->assertSame(Outcome::CityHeld, $after->outcome);
        $this->assertGreaterThan(0, $after->coreResilience);
    }

    public function test_a_finished_run_rejects_further_actions(): void
    {
        $level = $this->level();
        $state = $this->engine()->start($level);
        $state->turn = $level->maxTurns;
        $state->intent = $level->intentForTurn($level->maxTurns);

        [$after] = $this->act($state, 'gather', null);

        try {
            $this->act($after, 'gather', null);
            $this->fail('結束的局不應該接受行動');
        } catch (InvalidActionException $exception) {
            $this->assertSame('run_finished', $exception->reasonCode);
        }
    }

    public function test_the_city_keeps_only_one_shield_at_a_time(): void
    {
        $level = $this->level('meter-feast');
        $state = $this->engine()->start($level);
        $state->turn = 2;
        $state->intent = $level->intentForTurn(2);

        [$state] = $this->act($state, 'gather', null, $level);
        $this->assertCount(1, $state->shields);
        $this->assertSame($level->intentForTurn(2)->magnitude, $state->totalShield());

        $state->turn = 6;
        $state->intent = $level->intentForTurn(6);
        [$state] = $this->act($state, 'gather', null, $level);

        // 新護盾取代舊護盾，不累加。
        $this->assertCount(1, $state->shields);
        $this->assertSame($level->intentForTurn(6)->magnitude, $state->totalShield());
    }

    public function test_the_overhaul_phase_starts_and_can_be_stopped_by_two_different_elements(): void
    {
        $level = $this->level('stored-night');
        $state = $this->engine()->start($level);
        $state->coreResilience = 52;
        $state->turn = 2;
        $state->intent = $level->intentForTurn(2);

        [$state] = $this->act($state, 'probe.heat', Element::Heat, $level);
        $this->assertSame('overhaul', $state->phase);
        $this->assertSame(CityIntent::TYPE_OVERHAUL, $state->intent->type);
        $this->assertTrue($state->intent->interruptible);

        $element = $state->intent->element;
        [$state] = $this->act($state, 'disrupt.'.$element->value, $element, $level);
        $this->assertSame('overhaul', $state->phase);

        $second = $state->intent->element;
        [$state] = $this->act($state, 'disrupt.'.$second->value, $second, $level);

        $this->assertSame('standby', $state->phase);
        $this->assertTrue($state->flags['overhaul_stopped']);
    }

    /**
     * @param  list<BattleEvent>  $events
     */
    private function event(array $events, string $type): BattleEvent
    {
        $found = $this->maybeEvent($events, $type);
        $this->assertNotNull($found, "找不到 {$type} 事件");

        return $found;
    }

    /**
     * @param  list<BattleEvent>  $events
     */
    private function maybeEvent(array $events, string $type): ?BattleEvent
    {
        foreach ($events as $event) {
            if ($event->type === $type) {
                return $event;
            }
        }

        return null;
    }
}
