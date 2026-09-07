<?php

namespace App\Domain\Game;

/**
 * 一次成功結算的結果。伺服器先固定結果，Vue 再依序播放 events
 * （TECHNICAL-SPEC §6、GAME-DESIGN §7）。
 */
final readonly class TurnResult
{
    /**
     * @param  list<BattleEvent>  $events
     */
    public function __construct(
        public BattleState $state,
        public array $events,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsToArray(): array
    {
        return array_map(static fn (BattleEvent $event): array => $event->toArray(), $this->events);
    }
}
