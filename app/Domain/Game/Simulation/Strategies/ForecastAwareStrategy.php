<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Simulation\Strategy;
use App\Domain\Game\Skill;
use App\Domain\Game\SkillKind;
use Random\Randomizer;

/**
 * 看懂規則、會讀預告，但不做試算的玩家（P10 §3.2）。
 *
 * 只讀畫面上看得到的東西：手牌、卡面衝擊、目前預告、場上護盾、防線、上一次進攻系別。
 * 不呼叫 BattleEngine::apply()，也不複製傷害公式——它用的是玩家會有的經驗法則：
 * 能放終招就放、能打斷預告就打斷、不往同系護盾上打、盡量換系、挑衝擊最大的牌。
 */
class ForecastAwareStrategy implements Strategy
{
    public function __construct(private readonly CardCatalog $cards) {}

    public function name(): string
    {
        return 'forecast-aware';
    }

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        $plays = array_values(array_filter(
            $engine->legalActions($state),
            static fn (array $action): bool => $action['type'] === 'play',
        ));

        if ($plays === []) {
            return null;
        }

        $choice = $this->ultimate($state, $plays)
            ?? $this->interrupt($state, $plays)
            ?? $this->bestAttack($state, $plays)
            ?? $this->find($plays, 'gather');

        if ($choice === null) {
            return null;
        }

        $choice['keep'] = $this->keep($state, $choice);

        return $choice;
    }

    /**
     * 終招會被任何護盾吸收，所以場上有盾時先不放。
     *
     * @param  list<array<string, mixed>>  $plays
     * @return array<string, mixed>|null
     */
    private function ultimate(BattleState $state, array $plays): ?array
    {
        return $state->totalShield() > 0 ? null : $this->find($plays, 'ultimate');
    }

    /**
     * @param  list<array<string, mixed>>  $plays
     * @return array<string, mixed>|null
     */
    private function interrupt(BattleState $state, array $plays): ?array
    {
        $intent = $state->intent;

        if ($intent === null || ! $intent->interruptible) {
            return null;
        }

        return $this->find($plays, 'disrupt.'.$intent->element->value);
    }

    /**
     * 不打同系護盾；其餘依「換系 → 卡面衝擊 → 防線較低」排序。
     *
     * @param  list<array<string, mixed>>  $plays
     * @return array<string, mixed>|null
     */
    private function bestAttack(BattleState $state, array $plays): ?array
    {
        $shielded = array_column(array_filter($state->shields, static fn (array $shield): bool => $shield['amount'] > 0), 'element');
        $last = $state->lastAttackElement;
        $best = null;
        $bestKey = null;

        foreach ($plays as $action) {
            if ($action['card_id'] === null || $action['target'] === null) {
                continue;
            }

            $skill = $this->skill($state, $action['card_id']);

            if ($skill === null || $skill->kind === SkillKind::Disrupt || in_array($action['target'], $shielded, true)) {
                continue;
            }

            $key = [
                $action['target'] !== $last ? 1 : 0,
                $skill->baseImpact,
                -($state->defenses[$action['target']] ?? 0),
            ];

            if ($bestKey === null || $key > $bestKey) {
                $best = $action;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * 惡意不夠打的破陣留到下一手；同張牌不連續留。
     *
     * @param  array<string, mixed>  $choice
     * @return list<string>
     */
    private function keep(BattleState $state, array $choice): array
    {
        foreach ($state->hand as $instanceId) {
            if ($instanceId === $choice['card_id'] || in_array($instanceId, $state->keptLastTurn, true)) {
                continue;
            }

            if ($this->skill($state, $instanceId)?->kind === SkillKind::Breach) {
                return [$instanceId];
            }
        }

        return [];
    }

    private function skill(BattleState $state, string $instanceId): ?Skill
    {
        $type = $state->cardType($instanceId);

        return $type === null ? null : $this->cards->skillFor($type);
    }

    /**
     * @param  list<array<string, mixed>>  $plays
     * @return array<string, mixed>|null
     */
    private function find(array $plays, string $skillId): ?array
    {
        foreach ($plays as $action) {
            if ($action['skill_id'] === $skillId) {
                return $action;
            }
        }

        return null;
    }
}
