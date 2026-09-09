<?php

namespace App\Domain\Game\Cards;

use App\Domain\Game\Skill;
use App\Domain\Game\SkillCatalog;
use InvalidArgumentException;

/**
 * 所有牌型。牌組只列牌型與張數，實體牌在開局時展開（見 Deck）。
 */
class CardCatalog
{
    /** @var array<string, CardDefinition> */
    private array $cards = [];

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     */
    public function __construct(array $definitions, private readonly SkillCatalog $skills)
    {
        foreach ($definitions as $id => $definition) {
            $card = CardDefinition::fromConfig($id, $definition);

            if (! $skills->has($card->skillId)) {
                throw new InvalidArgumentException("牌型 {$id} 指向未知的技能代碼：{$card->skillId}");
            }

            $this->cards[$id] = $card;
        }
    }

    public function has(string $cardId): bool
    {
        return array_key_exists($cardId, $this->cards);
    }

    public function get(string $cardId): CardDefinition
    {
        if (! $this->has($cardId)) {
            throw new InvalidArgumentException("未知的牌型：{$cardId}");
        }

        return $this->cards[$cardId];
    }

    public function skillFor(string $cardId): Skill
    {
        return $this->skills->get($this->get($cardId)->skillId);
    }

    /**
     * @return array<string, CardDefinition>
     */
    public function all(): array
    {
        return $this->cards;
    }

    /**
     * 卡面資料表，前端用它渲染每一張牌。
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $payload = [];

        foreach ($this->cards as $id => $card) {
            $payload[$id] = $card->toArray($this->skills->get($card->skillId));
        }

        return $payload;
    }
}
