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
 * 驗收要求這條固定循環**不能**普遍通關（DEVELOPMENT-PLAN P03、
 * P04-REVISION-PLAN §1）。改成手牌之後它還多了一個限制：想打的那一招不在手上
 * 就打不出來，固定出招順序自然被發牌打斷。
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
        $ultimate = $this->find($legal, 'ultimate');

        if ($ultimate !== null) {
            return $ultimate;
        }

        // 印記最少的系別先補，複製舊版「三系各集三枚」的節奏。
        $ordered = Element::all();
        usort($ordered, static fn (Element $a, Element $b): int => $state->sigil($a) <=> $state->sigil($b));

        foreach ($ordered as $element) {
            $wanted = $this->find($legal, 'probe.'.$element->value);

            if ($wanted !== null) {
                return $wanted;
            }
        }

        return ['type' => 'play', 'card_id' => null, 'fixed' => 'gather', 'skill_id' => 'gather', 'target' => null];
    }

    /**
     * 舊套路認的是招式，不是牌。手上有兩張同招時任選一張，效果相同。
     *
     * @param  list<array<string, mixed>>  $legal
     * @return array<string, mixed>|null
     */
    private function find(array $legal, string $skillId): ?array
    {
        foreach ($legal as $action) {
            if ($action['type'] === 'play' && $action['skill_id'] === $skillId) {
                return $action;
            }
        }

        return null;
    }
}
