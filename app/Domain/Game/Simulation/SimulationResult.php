<?php

namespace App\Domain\Game\Simulation;

use App\Domain\Game\Outcome;

final readonly class SimulationResult
{
    /**
     * @param  list<array{turn: int, skill_id: string, target: string|null}>  $actions
     * @param  array<string, int>  $metrics  由結算事件統計的量測欄位，見 BattleSimulator::emptyMetrics()
     * @param  list<array{turn: int, from: string, to: string, reason_code: string}>  $phaseChanges
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
        public string $deck = 'starter',
        public int $maxTurns = 0,
        public array $metrics = [],
        public array $phaseChanges = [],
    ) {}

    public function won(): bool
    {
        return $this->outcome === Outcome::PlayerVictory;
    }

    /**
     * 勝利時用掉的回合比例；沒贏就沒有意義，回 null。
     */
    public function turnBudgetUsed(): ?float
    {
        if (! $this->won() || $this->maxTurns <= 0) {
            return null;
        }

        return $this->turns / $this->maxTurns;
    }
}
