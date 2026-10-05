<?php

namespace App\Services\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\SimulationResult;
use App\Domain\Game\Simulation\Strategies\ForecastAwareStrategy;
use App\Domain\Game\Simulation\Strategies\GreedyStrategy;
use App\Domain\Game\Simulation\Strategies\LegacyCycleStrategy;
use App\Domain\Game\Simulation\Strategies\PlannerOneMistakeStrategy;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Simulation\Strategies\RandomStrategy;
use App\Domain\Game\Simulation\Strategies\SingleElementStrategy;
use App\Domain\Game\Simulation\Strategy;
use App\Models\Campaign;
use InvalidArgumentException;

/**
 * P10 難度報告：關卡 × 情境 × 合法牌組 × 策略 × seed 的固定重跑與聚合。
 *
 * 只量測，不改規則。結果完全由 (commit, 參數, seed) 決定，報告不含時間戳，
 * 同樣的輸入必得同樣的 JSON。
 *
 * difficulty_index 只供自動排序與回歸，不取代真人試玩（P10 §3.3）。
 */
class DifficultyReport
{
    public const INDEX_VERSION = 'p10-di-1';

    public const INDEX_FORMULA = '0.35*(1-planner) + 0.25*(1-forecast_aware) + 0.25*(1-one_mistake) + 0.15*planner_winning_turn_budget';

    public const SUITES = ['quick', 'full'];

    /**
     * P10 §3.4 初始發布門檻（勝率區間，0～1）。P10-0 只對照、不調整關卡。
     *
     * @var array<int, array{planner: array{float, float}, forecast-aware: array{float, float}, planner-one-mistake: array{float, float}}>
     */
    public const TARGETS = [
        1 => ['planner' => [0.95, 1.00], 'forecast-aware' => [0.80, 0.95], 'planner-one-mistake' => [0.75, 0.90]],
        2 => ['planner' => [0.88, 0.95], 'forecast-aware' => [0.65, 0.82], 'planner-one-mistake' => [0.60, 0.75]],
        3 => ['planner' => [0.80, 0.90], 'forecast-aware' => [0.45, 0.68], 'planner-one-mistake' => [0.42, 0.60]],
        4 => ['planner' => [0.70, 0.82], 'forecast-aware' => [0.25, 0.52], 'planner-one-mistake' => [0.25, 0.45]],
        5 => ['planner' => [0.60, 0.75], 'forecast-aware' => [0.10, 0.38], 'planner-one-mistake' => [0.10, 0.30]],
    ];

    private const WEAK_STRATEGIES = ['random', 'single-water', 'legacy-cycle'];

    public function __construct(
        private readonly LevelRepository $levels,
        private readonly BattleEngine $engine,
        private readonly DeckComposer $decks,
        private readonly CardCatalog $cards,
    ) {}

    /**
     * 情境標籤以水／熱／土地的順序寫成 `w0h0l0`：`-` 為 −上限、`0` 中性、`+` 為 +上限。
     * quick 是 9 組（中性、全低、全高、各系單獨低或高），full 是 27 組完整排列。
     *
     * @return array<string, array<string, float>> 標籤 => [系別 => 修正值]
     */
    public function scenarios(string $suite): array
    {
        if (! in_array($suite, self::SUITES, true)) {
            throw new InvalidArgumentException("Unknown suite [{$suite}].");
        }

        $limit = (float) config('game.scenario.modifier_limit');
        $values = ['-' => -$limit, '0' => 0.0, '+' => $limit];
        $codes = [];

        if ($suite === 'full') {
            foreach (array_keys($values) as $water) {
                foreach (array_keys($values) as $heat) {
                    foreach (array_keys($values) as $land) {
                        $codes[] = [$water, $heat, $land];
                    }
                }
            }
        } else {
            $codes = [['0', '0', '0'], ['-', '-', '-'], ['+', '+', '+']];

            foreach ([0, 1, 2] as $position) {
                foreach (['-', '+'] as $sign) {
                    $code = ['0', '0', '0'];
                    $code[$position] = $sign;
                    $codes[] = $code;
                }
            }
        }

        $scenarios = [];

        foreach ($codes as [$water, $heat, $land]) {
            $scenarios["w{$water}h{$heat}l{$land}"] = [
                Element::Water->value => $values[$water],
                Element::Heat->value => $values[$heat],
                Element::Land->value => $values[$land],
            ];
        }

        return $scenarios;
    }

    /**
     * 玩家打到這一關時可能持有的所有牌組：前面每一個有獎勵的關卡各挑一個選項的笛卡兒積。
     * 組牌一律交給 DeckComposer，和正式開局同一條路徑。
     *
     * @return array<string, array<string, int>> 牌組標籤 => 組成
     */
    public function deckVariants(LevelDefinition $level): array
    {
        $choiceSets = [[]];

        foreach ($this->levels->all() as $candidate) {
            if ($candidate->reward === null || $candidate->sequence >= $level->sequence) {
                continue;
            }

            $next = [];

            foreach ($choiceSets as $choices) {
                foreach (array_keys($candidate->reward['options']) as $optionKey) {
                    $next[] = $choices + [$candidate->id => $optionKey];
                }
            }

            $choiceSets = $next;
        }

        $variants = [];

        foreach ($choiceSets as $choices) {
            $label = $choices === [] ? 'starter' : implode('+', $choices);
            $campaign = (new Campaign)->forceFill(['deck_choices' => $choices]);
            $variants[$label] = $this->decks->compose($level, $campaign);
        }

        return $variants;
    }

    /**
     * @return list<Strategy>
     */
    public function strategies(ScenarioModifiers $modifiers): array
    {
        return [
            new RandomStrategy,
            new SingleElementStrategy(Element::Water),
            new LegacyCycleStrategy,
            new GreedyStrategy($modifiers),
            new PlannerStrategy($modifiers, $this->cards),
            new ForecastAwareStrategy($this->cards),
            new PlannerOneMistakeStrategy($modifiers, $this->cards),
        ];
    }

    /**
     * @param  list<string>  $levelIds  空陣列代表全部關卡
     * @param  list<string>  $strategyNames  空陣列代表全部策略
     * @param  (callable(string): void)|null  $progress
     * @return array<string, mixed>
     */
    public function build(
        string $suite,
        int $seeds,
        int $startSeed,
        array $levelIds = [],
        array $strategyNames = [],
        ?callable $progress = null,
    ): array {
        $simulator = new BattleSimulator($this->engine);
        $scenarios = $this->scenarios($suite);
        $levelIds = $levelIds === [] ? $this->levels->ids() : $levelIds;
        $cells = [];

        foreach ($levelIds as $levelId) {
            $level = $this->levels->get($levelId);

            foreach ($this->deckVariants($level) as $deckLabel => $composition) {
                foreach ($scenarios as $scenarioLabel => $values) {
                    $modifiers = $this->modifiers($values);

                    foreach ($this->strategies($modifiers) as $strategy) {
                        if ($strategyNames !== [] && ! in_array($strategy->name(), $strategyNames, true)) {
                            continue;
                        }

                        $results = [];

                        for ($seed = $startSeed; $seed < $startSeed + $seeds; $seed++) {
                            $results[] = $simulator->run($level, $modifiers, $strategy, $seed, $scenarioLabel, $composition, $deckLabel);
                        }

                        $cells[] = $this->cell($level, $deckLabel, $scenarioLabel, $strategy->name(), $results);
                    }
                }
            }

            if ($progress !== null) {
                $progress($levelId);
            }
        }

        $levels = array_map(fn (string $levelId): array => $this->levelSummary($this->levels->get($levelId), $cells), $levelIds);

        return [
            'meta' => [
                'rules_version' => (string) config('game.rules_version'),
                'suite' => $suite,
                'seeds_per_cell' => $seeds,
                'start_seed' => $startSeed,
                'scenario_legend' => 'w/h/l = water/heat/land; - = -modifier_limit, 0 = neutral, + = +modifier_limit',
                'modifier_limit' => (float) config('game.scenario.modifier_limit'),
                'scenarios' => array_keys($scenarios),
                'index_version' => self::INDEX_VERSION,
                'index_formula' => self::INDEX_FORMULA,
                'weighting' => 'every (scenario, deck) cell of a level has equal weight',
                'solver' => 'not implemented in P10-0; planner results are not a solvability proof',
            ],
            'levels' => $levels,
            'progression' => $this->progression($levels),
            'cells' => $cells,
        ];
    }

    /**
     * @param  array<string, float>  $values
     */
    private function modifiers(array $values): ScenarioModifiers
    {
        $reasons = [];

        foreach ($values as $element => $value) {
            $reasons[$element] = [
                'code' => 'simulation_bound',
                'message' => '模擬用的固定情境上下界，不是實際觀測',
                'inputs' => ['value' => $value],
            ];
        }

        return new ScenarioModifiers($values, $reasons);
    }

    /**
     * @param  list<SimulationResult>  $results
     * @return array<string, mixed>
     */
    private function cell(LevelDefinition $level, string $deck, string $scenario, string $strategy, array $results): array
    {
        $games = count($results);
        $wins = array_values(array_filter($results, static fn (SimulationResult $result): bool => $result->won()));
        $losses = array_values(array_filter($results, static fn (SimulationResult $result): bool => ! $result->won()));
        $budgets = array_map(static fn (SimulationResult $result): float => (float) $result->turnBudgetUsed(), $wins);
        $metrics = [];

        foreach (array_keys(BattleSimulator::emptyMetrics()) as $key) {
            $metrics[$key] = round(array_sum(array_map(static fn (SimulationResult $result): int => $result->metrics[$key] ?? 0, $results)) / max(1, $games), 3);
        }

        return [
            'level' => $level->id,
            'sequence' => $level->sequence,
            'deck' => $deck,
            'scenario' => $scenario,
            'strategy' => $strategy,
            'games' => $games,
            'wins' => count($wins),
            'win_rate' => round(count($wins) / max(1, $games), 4),
            'avg_end_turn' => round(array_sum(array_map(static fn (SimulationResult $result): int => $result->turns, $results)) / max(1, $games), 3),
            'avg_winning_turn_budget_used' => $wins === [] ? null : round(array_sum($budgets) / count($budgets), 4),
            'avg_core_remaining_on_loss' => $losses === [] ? null : round(array_sum(array_map(static fn (SimulationResult $result): int => $result->coreRemaining, $losses)) / count($losses), 3),
            'illegal_choices' => array_sum(array_map(static fn (SimulationResult $result): int => $result->rejectedActions, $results)),
            'avg_phase_changes' => round(array_sum(array_map(static fn (SimulationResult $result): int => count($result->phaseChanges), $results)) / max(1, $games), 3),
            'avg_metrics' => $metrics,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @return array<string, mixed>
     */
    private function levelSummary(LevelDefinition $level, array $cells): array
    {
        $own = array_values(array_filter($cells, static fn (array $cell): bool => $cell['level'] === $level->id));
        $strategies = [];

        foreach (array_unique(array_column($own, 'strategy')) as $name) {
            $mine = array_values(array_filter($own, static fn (array $cell): bool => $cell['strategy'] === $name));
            $rates = array_column($mine, 'win_rate');
            $minIndex = array_keys($rates, min($rates))[0];
            $maxIndex = array_keys($rates, max($rates))[0];

            $strategies[$name] = [
                'cells' => count($mine),
                'weighted_win_rate' => round(array_sum($rates) / count($rates), 4),
                'min_cell' => ['win_rate' => $rates[$minIndex], 'scenario' => $mine[$minIndex]['scenario'], 'deck' => $mine[$minIndex]['deck']],
                'max_cell' => ['win_rate' => $rates[$maxIndex], 'scenario' => $mine[$maxIndex]['scenario'], 'deck' => $mine[$maxIndex]['deck']],
                'illegal_choices' => array_sum(array_column($mine, 'illegal_choices')),
            ];
        }

        return [
            'level' => $level->id,
            'sequence' => $level->sequence,
            'decks' => array_values(array_unique(array_column($own, 'deck'))),
            'strategies' => $strategies,
            'difficulty' => $this->difficulty($own, $strategies),
            'gates' => $this->gates($level, $own, $strategies),
        ];
    }

    /**
     * 四個成分缺任何一個（例如只跑部分策略）就不算指數，避免把殘缺數字當成排序依據。
     *
     * @param  list<array<string, mixed>>  $cells
     * @param  array<string, array<string, mixed>>  $strategies
     * @return array<string, float|null>
     */
    private function difficulty(array $cells, array $strategies): array
    {
        $planner = $strategies['planner']['weighted_win_rate'] ?? null;
        $forecast = $strategies['forecast-aware']['weighted_win_rate'] ?? null;
        $mistake = $strategies['planner-one-mistake']['weighted_win_rate'] ?? null;

        $plannerCells = array_values(array_filter($cells, static fn (array $cell): bool => $cell['strategy'] === 'planner' && $cell['wins'] > 0));
        $winningWeight = array_sum(array_column($plannerCells, 'wins'));
        $budget = $winningWeight === 0 ? null : array_sum(array_map(
            static fn (array $cell): float => $cell['avg_winning_turn_budget_used'] * $cell['wins'],
            $plannerCells,
        )) / $winningWeight;

        $index = ($planner === null || $forecast === null || $mistake === null || $budget === null)
            ? null
            : round(0.35 * (1 - $planner) + 0.25 * (1 - $forecast) + 0.25 * (1 - $mistake) + 0.15 * $budget, 4);

        return [
            'planner_win_rate' => $planner,
            'forecast_aware_win_rate' => $forecast,
            'one_mistake_recovery_rate' => $mistake,
            'planner_winning_turn_budget_used' => $budget === null ? null : round($budget, 4),
            'difficulty_index' => $index,
        ];
    }

    /**
     * 只對照 P10 §3.4 的門檻並列出失敗格，不做任何調整。
     *
     * @param  list<array<string, mixed>>  $cells
     * @param  array<string, array<string, mixed>>  $strategies
     * @return array<string, mixed>
     */
    private function gates(LevelDefinition $level, array $cells, array $strategies): array
    {
        $bands = [];

        foreach (self::TARGETS[$level->sequence] ?? [] as $name => [$low, $high]) {
            $rate = $strategies[$name]['weighted_win_rate'] ?? null;
            $bands[$name] = [
                'target' => [$low, $high],
                'actual' => $rate,
                'within' => $rate === null ? null : ($rate >= $low && $rate <= $high),
            ];
        }

        $plannerFloor = array_values(array_map(
            static fn (array $cell): array => ['scenario' => $cell['scenario'], 'deck' => $cell['deck'], 'win_rate' => $cell['win_rate']],
            array_filter($cells, static fn (array $cell): bool => $cell['strategy'] === 'planner' && $cell['win_rate'] < 0.55),
        ));

        $weakSolutions = $level->sequence < 2 ? [] : array_values(array_map(
            static fn (array $cell): array => ['strategy' => $cell['strategy'], 'scenario' => $cell['scenario'], 'deck' => $cell['deck'], 'win_rate' => $cell['win_rate']],
            array_filter($cells, static fn (array $cell): bool => in_array($cell['strategy'], self::WEAK_STRATEGIES, true) && $cell['win_rate'] >= 0.5),
        ));

        return [
            'bands' => $bands,
            'planner_cells_below_55' => $plannerFloor,
            'weak_strategy_cells_at_or_above_50' => $weakSolutions,
        ];
    }

    /**
     * 逐關 difficulty_index 是否依序上升且每步至少 +0.05（P10 §3.4 硬門檻 1）。
     *
     * @param  list<array<string, mixed>>  $levels
     * @return array<string, mixed>
     */
    private function progression(array $levels): array
    {
        usort($levels, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);
        $steps = [];

        for ($i = 1; $i < count($levels); $i++) {
            $previous = $levels[$i - 1]['difficulty']['difficulty_index'];
            $current = $levels[$i]['difficulty']['difficulty_index'];
            $delta = ($previous === null || $current === null) ? null : round($current - $previous, 4);

            $steps[] = [
                'from' => $levels[$i - 1]['level'],
                'to' => $levels[$i]['level'],
                'delta' => $delta,
                'passes' => $delta === null ? null : $delta >= 0.05,
            ];
        }

        $deltas = array_filter(array_column($steps, 'delta'), static fn (?float $delta): bool => $delta !== null);

        return [
            'required_step' => 0.05,
            'steps' => $steps,
            'monotonic_increasing' => $steps === [] || in_array(null, array_column($steps, 'delta'), true)
                ? null
                : min($deltas) > 0,
            'passes' => $steps === [] || in_array(null, array_column($steps, 'passes'), true)
                ? null
                : ! in_array(false, array_column($steps, 'passes'), true),
        ];
    }
}
