<?php

namespace App\Domain\Game\Solver;

final readonly class SolverResult
{
    /**
     * @param  list<array{type: string, card_id: string|null, fixed: string|null, keep: list<string>}>  $path  從開局起的完整行動序列（含揭牌）
     */
    public function __construct(
        public SolverStatus $status,
        public array $path,
        public int $nodesExpanded,
        public int $budget,
        public int $memoHits,
        public int $budgetCuts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'nodes_expanded' => $this->nodesExpanded,
            'budget' => $this->budget,
            'memo_hits' => $this->memoHits,
            'budget_cuts' => $this->budgetCuts,
            'path_length' => count($this->path),
            'path' => $this->path,
        ];
    }
}
