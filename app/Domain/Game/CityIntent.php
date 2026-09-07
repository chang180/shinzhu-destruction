<?php

namespace App\Domain\Game;

/**
 * 城市下一步預告。預告必須說明「會發生什麼、何時發生、可用什麼手段對應」，
 * 因此 interruptible 與 element 都要送到前端；後期可以隱藏增益細節，
 * 但不能藏起致命規則（GAME-DESIGN §2）。
 */
final readonly class CityIntent
{
    public const TYPE_REPAIR = 'repair';

    public const TYPE_SHIELD = 'shield';

    public const TYPE_REINFORCE = 'reinforce';

    public const TYPE_OVERHAUL = 'overhaul';

    public function __construct(
        public string $type,
        public Element $element,
        public int $magnitude,
        public bool $interruptible,
        public int $scheduledTurn,
        public string $description,
    ) {}

    /**
     * 擾序只能取消「可打斷且同系」的預告，不可取消無關系別（GAME-DESIGN §3.2）。
     */
    public function canBeInterruptedBy(Element $element): bool
    {
        return $this->interruptible && $this->element === $element;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'element' => $this->element->value,
            'magnitude' => $this->magnitude,
            'interruptible' => $this->interruptible,
            'scheduled_turn' => $this->scheduledTurn,
            'description' => $this->description,
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    public static function fromArray(array $intent): self
    {
        return new self(
            $intent['type'],
            Element::from($intent['element']),
            $intent['magnitude'],
            $intent['interruptible'],
            $intent['scheduled_turn'],
            $intent['description'],
        );
    }
}
