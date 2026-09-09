<?php

namespace App\Domain\Game\Cards;

use App\Domain\Game\Element;
use App\Domain\Game\Skill;

/**
 * 一種牌的定義（牌型），不是牌桌上的那一張。
 *
 * 牌型決定名稱、卡面說明與對應的技能代碼；規則計算只認 `skillId`，
 * 所以同一個技能可以有多種包裝，而換皮不會改動平衡。
 */
final readonly class CardDefinition
{
    public function __construct(
        public string $id,
        public string $skillId,
        public string $name,
        public string $text,
        public string $role,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromConfig(string $id, array $definition): self
    {
        return new self(
            id: $id,
            skillId: $definition['skill'],
            name: $definition['name'],
            text: $definition['text'],
            role: $definition['role'],
        );
    }

    /**
     * 卡面公開資料。成本與冷卻一律從技能表讀，不在牌型另寫一份數值。
     *
     * @return array<string, mixed>
     */
    public function toArray(Skill $skill): array
    {
        return [
            'card' => $this->id,
            'skill_id' => $this->skillId,
            'name' => $this->name,
            'text' => $this->text,
            'role' => $this->role,
            'kind' => $skill->kind->value,
            'element' => $skill->element?->value,
            'malice_cost' => $skill->maliceCost,
            'cooldown' => $skill->cooldown,
            'base_impact' => $skill->baseImpact,
            'defense_delta' => $skill->defenseDelta,
            'required_sigils' => $skill->requiredSigilsPerElement,
        ];
    }

    public function element(Skill $skill): ?Element
    {
        return $skill->element;
    }
}
