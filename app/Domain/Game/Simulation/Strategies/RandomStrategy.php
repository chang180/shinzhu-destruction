<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Simulation\Strategy;
use Random\Randomizer;

/**
 * 合法隨機。這是難度下界的對照組：任何策略贏不過它就代表機制沒有回報。
 */
class RandomStrategy implements Strategy
{
    public function name(): string
    {
        return 'random';
    }

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        $legal = $engine->legalActions($state);

        return $legal === [] ? null : $legal[$rng->getInt(0, count($legal) - 1)];
    }
}
