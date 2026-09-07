<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Element;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Simulation\Strategy;
use Random\Randomizer;

/**
 * 單系連按：能破陣就破陣，否則試探，都不行就蓄勢。
 *
 * 這條路線必須被適應抗性懲罰——如果它能穩定通關，代表換系與連攜沒有意義。
 */
class SingleElementStrategy implements Strategy
{
    public function __construct(private readonly Element $element) {}

    public function name(): string
    {
        return 'single-'.$this->element->value;
    }

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        $legal = $engine->legalActions($state);

        foreach (['breach', 'probe'] as $kind) {
            $wanted = ['skill_id' => $kind.'.'.$this->element->value, 'target' => $this->element->value];

            if (in_array($wanted, $legal, true)) {
                return $wanted;
            }
        }

        return ['skill_id' => 'gather', 'target' => null];
    }
}
