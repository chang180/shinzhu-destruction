<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Element;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Simulation\Strategy;
use Random\Randomizer;

/**
 * 舊版靜態原型的必勝套路：三系輪流試探集滿印記，然後放終招。
 *
 * 驗收要求這條固定循環在中後期關卡**不能**保證成功
 * （DEVELOPMENT-PLAN P03：舊版九次集印記必殺套路不再保證成功）。
 */
class LegacyCycleStrategy implements Strategy
{
    public function name(): string
    {
        return 'legacy-cycle';
    }

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        $legal = $engine->legalActions($state);
        $ultimate = ['skill_id' => 'ultimate', 'target' => null];

        if (in_array($ultimate, $legal, true)) {
            return $ultimate;
        }

        // 印記最少的系別先補，複製舊版「三系各集三枚」的節奏。
        $ordered = Element::all();
        usort($ordered, static fn (Element $a, Element $b): int => $state->sigil($a) <=> $state->sigil($b));

        foreach ($ordered as $element) {
            $wanted = ['skill_id' => 'probe.'.$element->value, 'target' => $element->value];

            if (in_array($wanted, $legal, true)) {
                return $wanted;
            }
        }

        return ['skill_id' => 'gather', 'target' => null];
    }
}
