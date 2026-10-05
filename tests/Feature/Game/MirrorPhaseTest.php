<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategy;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Randomizer;
use Tests\TestCase;

class MirrorPhaseTest extends TestCase
{
    use PlaysCards;

    public function test_mirror_and_scheduled_repairs_remain_visible_across_both_act_boundaries(): void
    {
        $level = $this->level('mirror-shade');
        $state = $this->startState($level);
        $boundaries = [];
        while (! $state->outcome->isFinished()) {
            $scheduled = $level->scheduledPhaseForTurn($state->turn);
            $this->assertSame($scheduled->id, $state->levelPhaseId);
            $mirror = $state->lastAttackElement === null ? null : Element::from($state->lastAttackElement);
            $this->assertSame($level->intentForTurn($state->turn, $mirror, $scheduled->id)->toArray(), $state->intent->toArray());
            $restored = BattleState::fromArray($state->toArray());
            $this->assertSame($state->toArray(), $restored->toArray());
            [$state, $events] = $this->act($state, 'gather', $level);
            foreach ($events as $event) {
                if ($event->type === 'level_phase_change') {
                    $boundaries[] = [$event->turn, $event->after['level_phase_id']];
                }
            }
        }

        $this->assertSame([[3, 'borrowed-shadow'], [6, 'counter-exam']], $boundaries);
        $this->assertSame('standard', $state->phase);
        $this->assertSame('repair', $level->intentForTurn(4, Element::Water, 'borrowed-shadow')->type);
        $this->assertSame(Element::Land, $level->intentForTurn(4, Element::Water, 'borrowed-shadow')->element);
        $this->assertSame(Element::Heat, $level->intentForTurn(7, Element::Heat, 'counter-exam')->element);
        $this->assertStringContainsString('鏡射', $level->intentForTurn(7, null, 'counter-exam')->description);
    }

    public function test_attacking_at_an_act_boundary_preserves_the_attack_history_and_obeys_the_next_visible_schedule(): void
    {
        $level = $this->level('mirror-shade');
        foreach ([3, 6] as $turn) {
            $state = $this->startState($level);
            $state->turn = $turn;
            $state->levelPhaseId = $level->scheduledPhaseForTurn($turn)->id;
            $state->lastAttackElement = 'water';
            $state->intent = $level->intentForTurn($turn, Element::Water, $state->levelPhaseId);
            [$next, $events] = $this->act($state, 'probe.heat', $level);

            $this->assertSame('heat', $next->lastAttackElement);
            $this->assertSame($turn + 1, $next->turn);
            $this->assertSame($level->intentForTurn($turn + 1, Element::Heat, $level->scheduledPhaseForTurn($turn + 1)->id)->toArray(), $next->intent->toArray());
            $this->assertContains('level_phase_change', array_map(fn ($event): string => $event->type, $events));
            $impacts = array_values(array_filter($events, fn ($event): bool => $event->type === 'impact'));
            $this->assertLessThan(0, $impacts[0]->delta['core_resilience']);
            $this->assertSame($state->deckSeed, $next->deckSeed);
        }
    }

    /** @return list<array{string, string, int, int, bool}> */
    public static function followups(): array
    {
        return [
            ['probe.water', 'breach.heat', 1, 1, true],
            ['probe.water', 'probe.heat', 1, 0, true],
            ['probe.water', 'breach.water', 0, 0, true],
            ['disrupt.water', 'breach.heat', 0, 0, true],
            ['probe.water', 'breach.heat', 0, 0, false],
            ['probe.water', 'ultimate', 0, 0, true],
            ['probe.water', 'timeout', 0, 0, true],
        ];
    }

    #[DataProvider('followups')]
    public function test_only_observed_mirrored_shields_followed_by_switched_core_hits_count(string $bait, string $followup, int $hits, int $breaches, bool $adaptive): void
    {
        $definition = config('game.levels.mirror-shade');
        if (! $adaptive) {
            unset($definition['adaptive_shield']);
        }
        $level = LevelDefinition::fromConfig('mirror-shade', $definition);
        $state = $this->startState($level, 1);
        $state->turn = 2;
        $state->lastAttackElement = 'water';
        $state->intent = $level->intentForTurn(2, Element::Water, $state->levelPhaseId);
        $state->malice = 10;
        $state->sigils = array_fill_keys(Element::values(), 3);
        $state = $this->stack($state, $bait);
        if (! in_array($followup, ['ultimate', 'timeout'], true)) {
            $state = $this->stack($state, $followup);
        }
        $state->turnPhase = BattleState::PHASE_DECISION;
        $cards = $this->cards();
        $strategy = new class($bait, $followup, $cards) implements Strategy
        {
            public function __construct(private string $bait, private string $followup, private CardCatalog $cards) {}

            public function name(): string
            {
                return 'mirror-fixture';
            }

            public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
            {
                $skill = match ($state->turn) {
                    2 => $this->bait, 3 => $this->followup, default => 'gather'
                };
                if ($skill === 'timeout') {
                    return ['type' => 'timeout'];
                }
                if (in_array($skill, ['gather', 'ultimate'], true)) {
                    return ['type' => 'play', 'fixed' => $skill];
                }
                foreach ($state->hand as $id) {
                    if ($this->cards->get($state->deck[$id])->skillId === $skill) {
                        return ['type' => 'play', 'card_id' => $id, 'keep' => $state->turn === 2 ? array_values(array_filter($state->hand, fn (string $held): bool => $held !== $id && $this->cards->get($state->deck[$held])->skillId === $this->followup)) : []];
                    }
                }

                return ['type' => 'play', 'fixed' => 'gather'];
            }
        };
        $simulator = new BattleSimulator($this->engine());
        $result = $simulator->continueFrom($state, $level, ScenarioModifiers::neutral(), $strategy, 1, 'mid');
        $again = $simulator->continueFrom($state, $level, ScenarioModifiers::neutral(), $strategy, 1, 'mid');

        $this->assertSame(0, $result->rejectedActions);
        $this->assertSame($hits, $result->metrics['mirror_switch_hits']);
        $this->assertSame($breaches, $result->metrics['mirror_switch_breaches']);
        $this->assertSame(get_object_vars($result), get_object_vars($again));
        if (! $adaptive) {
            $this->assertSame(0, $result->metrics['mirror_baits']);
        }
    }
}
