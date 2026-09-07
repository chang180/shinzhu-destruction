<?php

namespace App\Domain\Game\Simulation;

use App\Domain\Game\Outcome;

final readonly class SimulationResult
{
    /**
     * @param  list<array{turn: int, skill_id: string, target: string|null}>  $actions
     */
    public function __construct(
        public string $levelId,
        public string $strategy,
        public string $scenario,
        public int $seed,
        public Outcome $outcome,
        public int $turns,
        public int $coreRemaining,
        public int $rejectedActions,
        public array $actions,
    ) {}

    public function won(): bool
    {
        return $this->outcome === Outcome::PlayerVictory;
    }
}
