<?php

namespace App\Domain\Game;

/**
 * 一則結算事件。欄位足以解釋傷害、護盾、印記、抗性、修復及勝負
 * （TECHNICAL-SPEC §6），也是 P04 演出與 P07 複盤的唯一輸入。
 *
 * before／delta／after 一律是數值差異；cue_id 交給前端挑演出，
 * 客戶端不得再算一次傷害或決定勝負。
 */
final readonly class BattleEvent
{
    public const PLAYER = 'player';

    public const CITY = 'city';

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $delta
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public int $sequence,
        public int $turn,
        public string $type,
        public string $actor,
        public ?string $target,
        public string $reasonCode,
        public array $before,
        public array $delta,
        public array $after,
        public string $cueId,
    ) {}

    public function eventId(string $runId, string $actionId): string
    {
        return $runId.':'.$actionId.':'.$this->sequence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'turn' => $this->turn,
            'type' => $this->type,
            'actor' => $this->actor,
            'target' => $this->target,
            'reason_code' => $this->reasonCode,
            'before' => $this->before,
            'delta' => $this->delta,
            'after' => $this->after,
            'cue_id' => $this->cueId,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function fromArray(array $event): self
    {
        return new self(
            $event['sequence'],
            $event['turn'],
            $event['type'],
            $event['actor'],
            $event['target'],
            $event['reason_code'],
            $event['before'],
            $event['delta'],
            $event['after'],
            $event['cue_id'],
        );
    }
}
