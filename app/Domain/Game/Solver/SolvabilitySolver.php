<?php

namespace App\Domain\Game\Solver;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Outcome;
use App\Domain\Game\Scenario\ScenarioModifiers;

/**
 * 離線可解性搜尋：證明某個（關卡, 情境, 牌組, seed）**存在**一條通關路徑。
 *
 * 這不是玩家策略，也不進 difficulty_index。它可以讀完整確定性局面（包含未抽牌序），
 * 因為它只回答「有沒有解」，不代表任何人類玩家看得到的資訊。
 *
 * 行動模型涵蓋玩家所有合法輸入：揭牌（唯一選項）、每張可出的手牌與固定行動 ×
 * 所有合法留牌組合、每回合一次的換牌，以及逾時（挑戰模式下放著不出也是合法結果）。
 * 規則一律交給 BattleEngine::apply() 結算，不複製任何公式。
 *
 * 有限偏離搜尋（limited discrepancy search）：子節點依局面評估排序，第 k 輪只允許偏離
 * 首選 k 次（k = 0, 1, 2, 3, 4，最後一輪不設限），讓「前面幾步換個選擇」的解法很快被找到。
 * 最後一輪就是完整深度優先搜尋，所以搜尋是完備的。
 *
 * 以標準化局面雜湊做記憶化。只有完整展開過（沒有被預算截斷、也沒有因偏離上限略過子節點）
 * 的失敗局面才記為死局，所以預算用完只能回報 ExhaustedUnknown，不能寫成無解；
 * ExhaustivelyUnsolved 只在不設限的那一輪完整跑完、沒有任何截斷時成立。
 */
final class SolvabilitySolver
{
    private int $nodes = 0;

    private int $budget = 0;

    private int $memoHits = 0;

    private int $budgetCuts = 0;

    /** 本輪因偏離上限而略過的子節點數；只要 > 0，這一輪的失敗就不完整。 */
    private int $skips = 0;

    /** 偏離上限排程；null 代表不設限（完整深度優先）。 */
    private const DISCREPANCY_SCHEDULE = [0, 1, 2, 3, 4, null];

    /** @var array<string, true> */
    private array $dead = [];

    /** @var list<array{type: string, card_id: string|null, fixed: string|null, keep: list<string>}> */
    private array $path = [];

    public function __construct(private readonly BattleEngine $engine) {}

    /**
     * @param  array<string, int>|null  $composition
     */
    public function solve(LevelDefinition $level, ScenarioModifiers $modifiers, int $seed, ?array $composition, int $budget): SolverResult
    {
        return $this->solveFrom($this->engine->start($level, $seed, $composition), $level, $modifiers, $budget);
    }

    public function solveFrom(BattleState $state, LevelDefinition $level, ScenarioModifiers $modifiers, int $budget): SolverResult
    {
        $this->nodes = 0;
        $this->budget = $budget;
        $this->memoHits = 0;
        $this->budgetCuts = 0;
        $this->dead = [];
        $found = false;
        $complete = false;

        foreach (self::DISCREPANCY_SCHEDULE as $limit) {
            $this->path = [];
            $this->skips = 0;
            $cutsBefore = $this->budgetCuts;
            $found = $this->search($state, $level, $modifiers, $limit);

            if ($found) {
                break;
            }

            if ($this->budgetCuts === $cutsBefore && $this->skips === 0) {
                $complete = true;

                break;
            }

            if ($this->nodes >= $this->budget) {
                break;
            }
        }

        return new SolverResult(
            status: match (true) {
                $found => SolverStatus::Solved,
                $complete => SolverStatus::ExhaustivelyUnsolved,
                default => SolverStatus::ExhaustedUnknown,
            },
            path: $found ? $this->path : [],
            nodesExpanded: $this->nodes,
            budget: $budget,
            memoHits: $this->memoHits,
            budgetCuts: $this->budgetCuts,
        );
    }

    /**
     * 從開局照路徑逐步結算；任何一步不合法都會丟 InvalidActionException。
     *
     * @param  list<array{type: string, card_id: string|null, fixed: string|null, keep: list<string>}>  $path
     * @param  array<string, int>|null  $composition
     */
    public function replay(LevelDefinition $level, ScenarioModifiers $modifiers, int $seed, ?array $composition, array $path): BattleState
    {
        $state = $this->engine->start($level, $seed, $composition);

        foreach ($path as $index => $step) {
            $state = $this->engine->apply($state, self::request($state, $step, 'replay-'.$index), $level, $modifiers)->state;
        }

        return $state;
    }

    /**
     * 標準化局面雜湊：完整存檔去掉版本號與截止時間（它們只記錄「走了幾步」或時鐘，
     * 不影響之後的規則結果），關聯陣列依鍵排序，避免同一局面因旗標寫入順序不同被當成兩個。
     */
    public static function canonicalHash(BattleState $state): string
    {
        $array = $state->toArray();
        unset($array['version'], $array['deadline_at']);

        return sha1(json_encode(self::canonicalize($array)));
    }

    private function search(BattleState $state, LevelDefinition $level, ScenarioModifiers $modifiers, ?int $discrepancies): bool
    {
        if ($state->outcome === Outcome::PlayerVictory) {
            return true;
        }

        if ($state->outcome->isFinished()) {
            return false;
        }

        $hash = self::canonicalHash($state);

        if (isset($this->dead[$hash])) {
            $this->memoHits++;

            return false;
        }

        if ($this->nodes >= $this->budget) {
            $this->budgetCuts++;

            return false;
        }

        $this->nodes++;
        $cutsBefore = $this->budgetCuts;
        $skipsBefore = $this->skips;

        foreach ($this->children($state, $level, $modifiers) as $index => [$step, $next]) {
            $remaining = $discrepancies;

            if ($index > 0 && $discrepancies !== null) {
                if ($discrepancies === 0) {
                    $this->skips++;

                    break;
                }

                $remaining = $discrepancies - 1;
            }

            $this->path[] = $step;

            if ($this->search($next, $level, $modifiers, $remaining)) {
                return true;
            }

            array_pop($this->path);
        }

        if ($this->budgetCuts === $cutsBefore && $this->skips === $skipsBefore) {
            $this->dead[$hash] = true;
        }

        return false;
    }

    /**
     * @return list<array{0: array{type: string, card_id: string|null, fixed: string|null, keep: list<string>}, 1: BattleState}>
     */
    private function children(BattleState $state, LevelDefinition $level, ScenarioModifiers $modifiers): array
    {
        if ($state->turnPhase === BattleState::PHASE_AWAITING_REVEAL) {
            $step = ['type' => 'reveal', 'card_id' => null, 'fixed' => null, 'keep' => []];

            return [[$step, $this->engine->apply($state, self::request($state, $step, 'solver'), $level, $modifiers)->state]];
        }

        $candidates = [];

        foreach ($this->engine->legalActions($state) as $action) {
            if ($action['type'] === 'play') {
                foreach ($this->keepOptions($state, $action['card_id']) as $keep) {
                    $candidates[] = ['type' => 'play', 'card_id' => $action['card_id'], 'fixed' => $action['fixed'], 'keep' => $keep];
                }
            } elseif ($action['type'] === 'swap') {
                $candidates[] = ['type' => 'swap', 'card_id' => $action['card_id'], 'fixed' => null, 'keep' => []];
            }
        }

        $candidates[] = ['type' => 'timeout', 'card_id' => null, 'fixed' => null, 'keep' => []];

        $children = [];

        foreach ($candidates as $index => $step) {
            try {
                $next = $this->engine->apply($state, self::request($state, $step, 'solver'), $level, $modifiers)->state;
            } catch (InvalidActionException) {
                continue;
            }

            $children[] = [$step, $next, self::order($step, $next, $index)];
        }

        usort($children, static fn (array $a, array $b): int => $a[2] <=> $b[2]);

        return array_map(static fn (array $child): array => [$child[0], $child[1]], $children);
    }

    /**
     * 可留下的手牌組合：不含打出的那張、不含上一手已留過的牌，張數 0～maxKeep。
     *
     * @return list<list<string>>
     */
    private function keepOptions(BattleState $state, ?string $playedCardId): array
    {
        $keepable = array_values(array_filter(
            $state->hand,
            static fn (string $id): bool => $id !== $playedCardId && ! in_array($id, $state->keptLastTurn, true),
        ));
        sort($keepable);

        $options = [[]];

        foreach ($keepable as $card) {
            foreach ($options as $option) {
                if (count($option) < $state->maxKeep) {
                    $options[] = [...$option, $card];
                }
            }
        }

        usort($options, static fn (array $a, array $b): int => [count($a), $a] <=> [count($b), $b]);

        return $options;
    }

    /**
     * 排序鍵：立即勝利優先；出牌優先於換牌，逾時最後；再依局面評估由佳到差，同分少留牌、保持列舉順序。
     * 排序只影響找到路徑的速度，不影響結論的正確性——每個子節點都會被展開，除非預算用完。
     *
     * @param  array{type: string, card_id: string|null, fixed: string|null, keep: list<string>}  $step
     * @return list<int|float>
     */
    private static function order(array $step, BattleState $next, int $index): array
    {
        return [
            $next->outcome === Outcome::PlayerVictory ? 0 : 1,
            match ($step['type']) {
                'play' => 0,
                'swap' => 1,
                default => 2,
            },
            self::evaluate($next),
            count($step['keep']),
            $index,
        ];
    }

    /**
     * 局面評估，越低越接近通關：剩餘核心為主，破綻、終招印記、惡意可抵扣，
     * 適應抗性與護盾加重。只讀局面數字，不重算傷害公式。
     */
    private static function evaluate(BattleState $state): float
    {
        $sigilProgress = array_sum(array_map(static fn (int $sigils): int => min(2, $sigils), $state->sigils));

        return $state->coreResilience
            - ($state->breachAvailable ? 6.0 : 0.0)
            - 1.5 * $sigilProgress
            - 0.25 * $state->malice
            + 2.0 * array_sum($state->resistance)
            + 0.5 * $state->totalShield();
    }

    /**
     * @param  array{type: string, card_id: string|null, fixed: string|null, keep: list<string>}  $step
     */
    private static function request(BattleState $state, array $step, string $actionId): ActionRequest
    {
        return new ActionRequest(
            actionId: $actionId,
            expectedVersion: $state->version,
            type: ActionType::from($step['type']),
            cardId: $step['card_id'],
            fixedSkillId: $step['fixed'],
            keep: $step['keep'],
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonicalize(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
