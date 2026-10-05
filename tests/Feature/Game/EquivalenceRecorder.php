<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleEvent;
use App\Domain\Game\BattleState;
use App\Domain\Game\Element;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategy;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * P10-1 等價證據的錄製器。
 *
 * 同一份程式碼在 P10-1 動工前（基準 commit）產生 tests/Fixtures/p10-1/equivalence.json，
 * 動工後由 LevelPhaseEquivalenceTest 重錄並逐項比對，所以兩邊的錄製方式完全相同。
 * 公開局面只比對基準存在的欄位：新增的關卡幕次欄位不屬於「既有欄位」。
 */
final class EquivalenceRecorder
{
    public function __construct(private readonly BattleEngine $engine) {}

    public static function modifiers(float $value): ScenarioModifiers
    {
        return new ScenarioModifiers(
            array_fill_keys(Element::values(), $value),
            array_fill_keys(Element::values(), ['code' => 'fixture', 'message' => '', 'inputs' => []]),
        );
    }

    /**
     * 照 BattleSimulator 的流程打完一局，逐次結算記下事件與公開局面的雜湊。
     *
     * @param  list<string>  $publicKeys
     * @return array<string, mixed>
     */
    public function record(
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
        Strategy $strategy,
        int $seed,
        array $publicKeys,
        bool $fullEvents = false,
        ?BattleState $from = null,
    ): array {
        $state = $from ?? $this->engine->start($level, $seed);
        $rng = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'shinzhu:'.$seed, true)));
        $steps = [];
        $events = [];
        $counter = 0;

        while (! $state->outcome->isFinished()) {
            if ($state->turnPhase === BattleState::PHASE_AWAITING_REVEAL) {
                $request = new ActionRequest('fx-'.(++$counter), $state->version, ActionType::Reveal);
                $choice = ['type' => 'reveal'];
            } else {
                $choice = $strategy->choose($state, $this->engine, $level, $rng);
                $request = new ActionRequest(
                    actionId: 'fx-'.(++$counter),
                    expectedVersion: $state->version,
                    type: ActionType::from($choice['type']),
                    cardId: $choice['card_id'] ?? null,
                    fixedSkillId: $choice['fixed'] ?? null,
                    keep: $choice['keep'] ?? [],
                );
            }

            $result = $this->engine->apply($state, $request, $level, $modifiers);
            $eventArrays = array_map(static fn (BattleEvent $event): array => $event->toArray(), $result->events);
            $state = $result->state;

            $steps[] = [
                'action' => ['type' => $request->type->value, 'card_id' => $request->cardId, 'fixed' => $request->fixedSkillId, 'keep' => $request->keep],
                'events' => sha1(json_encode($eventArrays)),
                'state' => sha1(json_encode(self::pick($state->toPublicArray(), $publicKeys))),
            ];

            if ($fullEvents) {
                $events = [...$events, ...$eventArrays];
            }
        }

        return array_filter([
            'outcome' => $state->outcome->value,
            'turn' => $state->turn,
            'core' => $state->coreResilience,
            'final_state' => self::pick($state->toPublicArray(), $publicKeys),
            'steps' => $steps,
            'events' => $fullEvents ? $events : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * P07 的核心步驟：在第 $decision 個決策換成蓄勢，交給同一策略續打。
     *
     * @return array<string, mixed>
     */
    public function counterfactual(LevelDefinition $level, ScenarioModifiers $modifiers, Strategy $strategy, int $seed, int $decision): array
    {
        $state = $this->engine->start($level, $seed);
        $rng = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'shinzhu:'.$seed, true)));
        $decisions = 0;

        while (! $state->outcome->isFinished()) {
            if ($state->turnPhase === BattleState::PHASE_AWAITING_REVEAL) {
                $state = $this->engine->apply($state, new ActionRequest('cf-r', $state->version, ActionType::Reveal), $level, $modifiers)->state;

                continue;
            }

            if (++$decisions === $decision) {
                $changed = $this->engine->apply($state, new ActionRequest('cf', $state->version, ActionType::Play, fixedSkillId: 'gather'), $level, $modifiers)->state;
                $result = (new BattleSimulator($this->engine))->continueFrom($changed, $level, $modifiers, $strategy, $seed, 'counterfactual');

                return [
                    'decision' => $decision,
                    'outcome' => $result->outcome->value,
                    'turns' => $result->turns,
                    'core_remaining' => $result->coreRemaining,
                    'actions' => $result->actions,
                ];
            }

            $choice = $strategy->choose($state, $this->engine, $level, $rng);
            $state = $this->engine->apply($state, new ActionRequest(
                actionId: 'cf-p',
                expectedVersion: $state->version,
                type: ActionType::Play,
                cardId: $choice['card_id'] ?? null,
                fixedSkillId: $choice['fixed'] ?? null,
                keep: $choice['keep'] ?? [],
            ), $level, $modifiers)->state;
        }

        return ['decision' => $decision, 'outcome' => 'finished_before_decision'];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function pick(array $state, array $keys): array
    {
        return array_intersect_key($state, array_flip($keys));
    }
}
