<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Element;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\Strategy;
use App\Domain\Game\TurnResult;
use Random\Randomizer;

/**
 * 只往前看一步的策略基底。
 *
 * 用真正的引擎試算每個合法行動，不自己複製傷害公式——規則只有一份。
 * 一步等於「本回合的預告」，那正是玩家在畫面上看得到的資訊；不做更深的搜尋，
 * 避免策略偷看玩家看不到的未來回合。
 */
abstract class LookaheadStrategy implements Strategy
{
    public function __construct(private readonly ScenarioModifiers $modifiers) {}

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        $legal = $engine->legalActions($state);

        if ($legal === []) {
            return null;
        }

        $best = null;
        $bestScore = -INF;

        foreach ($legal as $action) {
            try {
                $result = $engine->apply(
                    $state,
                    new ActionRequest('lookahead', $state->version, $action['skill_id'], $this->element($action)),
                    $level,
                    $this->modifiers,
                );
            } catch (InvalidActionException) {
                continue;
            }

            $score = $this->score($state, $result, $action);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $action;
            }
        }

        return $best ?? ['skill_id' => 'gather', 'target' => null];
    }

    /**
     * @param  array{skill_id: string, target: string|null}  $action
     */
    abstract protected function score(BattleState $before, TurnResult $result, array $action): float;

    /**
     * @param  array{skill_id: string, target: string|null}  $action
     */
    protected function element(array $action): ?Element
    {
        return $action['target'] === null ? null : Element::from($action['target']);
    }

    /**
     * 本回合實際打進核心的量（城市回應之後的淨值）。
     */
    protected function coreDelta(BattleState $before, TurnResult $result): int
    {
        return $before->coreResilience - $result->state->coreResilience;
    }

    protected function hasEvent(TurnResult $result, string $type): bool
    {
        foreach ($result->events as $event) {
            if ($event->type === $type) {
                return true;
            }
        }

        return false;
    }
}
