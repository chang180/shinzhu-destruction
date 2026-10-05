<?php

namespace App\Domain\Game\Simulation;

use App\Domain\Game\Outcome;

final readonly class SimulationResult
{
    /**
     * @param  list<array{turn: int, skill_id: string, target: string|null}>  $actions
     * @param  array<string, int>  $metrics  由結算事件統計的量測欄位，見 BattleSimulator::emptyMetrics()
     * @param  list<array{turn: int, from: string, to: string, reason_code: string}>  $phaseChanges  機制狀態變化（第 5 關重整 standby／overhaul），不是幕次
     * @param  list<array{turn: int, from: string|null, to: string, reason_code: string}>  $levelPhaseChanges  關卡幕次變化（P10-1）
     * @param  array<string, mixed>  $strategyReport  InstrumentedStrategy 的逐局紀錄（例如失誤注入）
     * @param  list<int>  $ultimateTurns  實際終招結算事件所在回合；未使用為空陣列
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
        public array $strategyReport = [],
        public array $levelPhaseChanges = [],
        public array $ultimateTurns = [],
        public ?int $terminalMalice = null,
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
