<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Simulation\Strategy;
use Random\Randomizer;

/**
 * 合法隨機出牌。這是難度下界的對照組：任何策略贏不過它就代表機制沒有回報。
 * 只挑出牌，不換牌也不留牌——換牌是策略，混進對照組會量不準。
 */
class RandomStrategy implements Strategy
{
    public function name(): string
    {
        return 'random';
    }

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        $legal = array_values(array_filter(
            $engine->legalActions($state),
            static fn (array $action): bool => $action['type'] === 'play',
        ));

        return $legal === [] ? null : $legal[$rng->getInt(0, count($legal) - 1)];
    }
}
