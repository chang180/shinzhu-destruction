<?php

namespace App\Services\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Phases\LevelPhaseDefinition;
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
    public const INDEX_VERSION = 'p10-di-3';

    public const INDEX_FORMULA = '0.35*(1-planner) + 0.25*(1-forecast_aware) + 0.25*(1-one_mistake_recovery) + 0.15*planner_winning_turn_budget';

    /**
     * 先前版本的第三項語意，只為說明用，不再由本報告計算：
     * p10-di-1（P10-0）用無條件勝率且失誤可能只是同招另一張牌；
     * p10-di-2（P10-0.1～P10-2）用配對恢復率，但失誤只要求「語義不同且分數嚴格較低」，
     * 實測約七成不是機制錯誤。兩者的數字留在各自的階段報告，不能和 p10-di-3 混用。
     */
    public const SUPERSEDED_INDEX_VERSIONS = ['p10-di-1', 'p10-di-2'];

    /**
     * 恢復率的分母只涵蓋「planner 原本通關且當局真的有明確機制錯誤可注入」的局。
     * 覆蓋率必須一起報，否則未來調關卡時恢復率可能只是因為分母縮小才變好看。
     */
    public const ONE_MISTAKE_COVERAGE = 'eligible_mistake_games / planner_baseline_wins; how much of the planner-cleared population the recovery rate actually covers';

    public const ONE_MISTAKE_RECOVERY = 'paired by (level, deck, scenario, seed): recoveries_after_mistake / eligible_mistake_games; eligible = planner won and one mechanic mistake was injected (missed_interrupt: planner would interrupt an interruptible repair/shield/overhaul and we did not; walked_into_shield: planner bypassed a standing same-element shield and we attacked into it); pooled over the level';

    public const SUITES = ['quick', 'full'];

    /**
     * P10 §3.4 硬門檻 3：planner 任一（情境, 牌組）格的勝率下限。
     *
     * 取代 P05 時代 StrategyMatrixTest 裡逐情境 70% 的寫法——P10 從第 3 關起把
     * 逐關目標改成加權區間（第 3 關 80–90%、第 5 關 60–75%），70% 的逐格門檻會
     * 排除 P10 明確允許的 55–70% 格。逐關區間由 `game:difficulty-report` 的 gates 檢查。
     */
    public const PLANNER_CELL_FLOOR = 0.55;

    /**
     * 幕次量測（P10-2 起）。報告格式因此從 P10-0 的版本升級；difficulty_index 公式不變。
     */
    public const LEVEL_PHASE_METRICS = 'per cell: reach_rate = games whose run entered the act / games (act 1 = 1.0); avg_entry_turn = mean first turn played in the act among games that reached it';

    /**
     * 逐格 CSV 的幕次欄位最多列到第幾幕（P10 每關三幕）。
     */
    public const CSV_LEVEL_PHASES = 3;

    /**
     * P10 §3.4 初始發布門檻（勝率區間，0～1）。P10-0 只對照、不調整關卡。
     *
     * @var array<int, array{planner: array{float, float}, forecast-aware: array{float, float}, one-mistake-recovery: array{float, float}}>
     */
    public const TARGETS = [
        1 => ['planner' => [0.95, 1.00], 'forecast-aware' => [0.80, 0.95], 'one-mistake-recovery' => [0.75, 0.90]],
        2 => ['planner' => [0.88, 0.95], 'forecast-aware' => [0.65, 0.82], 'one-mistake-recovery' => [0.60, 0.75]],
        3 => ['planner' => [0.80, 0.90], 'forecast-aware' => [0.45, 0.68], 'one-mistake-recovery' => [0.42, 0.60]],
        4 => ['planner' => [0.70, 0.82], 'forecast-aware' => [0.25, 0.52], 'one-mistake-recovery' => [0.25, 0.45]],
        5 => ['planner' => [0.60, 0.75], 'forecast-aware' => [0.10, 0.38], 'one-mistake-recovery' => [0.10, 0.30]],
    ];

    /**
     * 逐局失誤紀錄的欄位，順序即 --mistakes-csv 的欄位順序。
     */
    public const MISTAKE_COLUMNS = [
        'level', 'deck', 'scenario', 'seed', 'planner_won', 'won_after_mistake', 'status', 'turn',
        'best_signature', 'best_card', 'best_score', 'mistake_signature', 'mistake_card', 'mistake_score',
        'score_delta', 'mistake_kind', 'intent_type', 'intent_element', 'shielded_elements',
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
     * @param  (callable(array<string, mixed>): void)|null  $onMistakeGame  逐局失誤紀錄（欄位見 MISTAKE_COLUMNS）；
     *                                                                      用串流交出，避免完整矩陣把每一局都留在記憶體
     * @return array<string, mixed>
     */
    public function build(
        string $suite,
        int $seeds,
        int $startSeed,
        array $levelIds = [],
        array $strategyNames = [],
        ?callable $progress = null,
        ?callable $onMistakeGame = null,
    ): array {
        $this->validate($suite, $seeds, $levelIds, $strategyNames);

        $simulator = new BattleSimulator($this->engine);
        $scenarios = $this->scenarios($suite);
        $levelIds = $levelIds === [] ? $this->levels->ids() : $levelIds;
        $cells = [];

        foreach ($levelIds as $levelId) {
            $level = $this->levels->get($levelId);

            foreach ($this->deckVariants($level) as $deckLabel => $composition) {
                foreach ($scenarios as $scenarioLabel => $values) {
                    $modifiers = $this->modifiers($values);
                    $run = function (Strategy $strategy) use ($simulator, $level, $modifiers, $scenarioLabel, $composition, $deckLabel, $seeds, $startSeed): array {
                        $results = [];

                        for ($seed = $startSeed; $seed < $startSeed + $seeds; $seed++) {
                            $results[] = $simulator->run($level, $modifiers, $strategy, $seed, $scenarioLabel, $composition, $deckLabel);
                        }

                        return $results;
                    };
                    $plannerResults = null;

                    foreach ($this->strategies($modifiers) as $strategy) {
                        $selected = $strategyNames === [] || in_array($strategy->name(), $strategyNames, true);

                        // 失誤恢復率要和同 seed 的 planner 配對；只跑 one-mistake 時仍要算 planner 基準。
                        if ($strategy->name() === 'planner' && ($selected || in_array('planner-one-mistake', $strategyNames, true))) {
                            $plannerResults = $run($strategy);
                        }

                        if (! $selected) {
                            continue;
                        }

                        $results = $strategy->name() === 'planner' ? $plannerResults : $run($strategy);
                        $cell = $this->cell($level, $deckLabel, $scenarioLabel, $strategy->name(), $results);

                        if ($strategy->name() === 'planner-one-mistake') {
                            $cell += $this->recovery($results, $plannerResults ?? [], $onMistakeGame);
                        }

                        $cells[] = $cell;
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
                'one_mistake_recovery' => self::ONE_MISTAKE_RECOVERY,
                'one_mistake_coverage' => self::ONE_MISTAKE_COVERAGE,
                'superseded_index_versions' => self::SUPERSEDED_INDEX_VERSIONS,
                'superseded_index_note' => 'p10-di-1 and p10-di-2 used different one-mistake definitions and are not comparable with p10-di-3; their numbers stay in the P10-0 and P10-2 reports. difficulty_index_unconditional_variant substitutes the unconditional planner-one-mistake win rate into the same formula and is only a sanity check, not the index.',
                'weighting' => 'every (scenario, deck) cell of a level has equal weight',
                'solver' => 'separate offline command game:solve (P10-1); planner results are not a solvability proof',
                'level_phase_metrics' => self::LEVEL_PHASE_METRICS,
            ],
            'levels' => $levels,
            'progression' => $this->progression($levels),
            'cells' => $cells,
        ];
    }

    /**
     * 所有可用的策略名稱，供命令列驗證。
     *
     * @return list<string>
     */
    public function strategyNames(): array
    {
        return array_map(static fn (Strategy $strategy): string => $strategy->name(), $this->strategies($this->modifiers([
            Element::Water->value => 0.0,
            Element::Heat->value => 0.0,
            Element::Land->value => 0.0,
        ])));
    }

    /**
     * 拼錯的關卡或策略不能默默產生一份空報告。
     *
     * @param  list<string>  $levelIds
     * @param  list<string>  $strategyNames
     */
    private function validate(string $suite, int $seeds, array $levelIds, array $strategyNames): void
    {
        if (! in_array($suite, self::SUITES, true)) {
            throw new InvalidArgumentException("Unknown suite [{$suite}].");
        }

        if ($seeds < 1) {
            throw new InvalidArgumentException('Seeds must be at least 1.');
        }

        $unknownLevels = array_values(array_filter($levelIds, fn (string $id): bool => ! $this->levels->has($id)));

        if ($unknownLevels !== []) {
            throw new InvalidArgumentException('Unknown level ['.implode(', ', $unknownLevels).']. Available: '.implode(', ', $this->levels->ids()).'.');
        }

        $unknownStrategies = array_values(array_diff($strategyNames, $this->strategyNames()));

        if ($unknownStrategies !== []) {
            throw new InvalidArgumentException('Unknown strategy ['.implode(', ', $unknownStrategies).']. Available: '.implode(', ', $this->strategyNames()).'.');
        }
    }

    /**
     * 同 seed 配對的失誤恢復：planner 原本通關、而且這一局真的注入了失誤，才進分母。
     *
     * @param  list<SimulationResult>  $mistakeResults
     * @param  list<SimulationResult>  $plannerResults
     * @return array<string, mixed>
     */
    private function recovery(array $mistakeResults, array $plannerResults, ?callable $onMistakeGame): array
    {
        $plannerBySeed = [];

        foreach ($plannerResults as $result) {
            $plannerBySeed[$result->seed] = $result;
        }

        $counts = [
            'planner_baseline_wins' => 0,
            'mistakes_injected' => 0,
            'eligible_mistake_games' => 0,
            'recoveries_after_mistake' => 0,
            'no_eligible_mistake_games' => 0,
            'missed_interrupt_mistakes' => 0,
            'walked_into_shield_mistakes' => 0,
        ];
        $deltas = [];
        $terminalBest = 0;
        $turns = [];

        foreach ($mistakeResults as $result) {
            $report = $result->strategyReport;
            $status = $report['status'] ?? null;
            $baselineWon = ($plannerBySeed[$result->seed] ?? null)?->won() ?? false;
            $injected = $status === PlannerOneMistakeStrategy::STATUS_INJECTED;

            $counts['planner_baseline_wins'] += $baselineWon ? 1 : 0;
            $counts['mistakes_injected'] += $injected ? 1 : 0;
            $counts['no_eligible_mistake_games'] += $status === PlannerOneMistakeStrategy::STATUS_NO_ELIGIBLE ? 1 : 0;
            $counts['missed_interrupt_mistakes'] += ($report['mistake_kind'] ?? null) === PlannerOneMistakeStrategy::KIND_MISSED_INTERRUPT ? 1 : 0;
            $counts['walked_into_shield_mistakes'] += ($report['mistake_kind'] ?? null) === PlannerOneMistakeStrategy::KIND_WALKED_INTO_SHIELD ? 1 : 0;

            if ($baselineWon && $injected) {
                $counts['eligible_mistake_games']++;
                $counts['recoveries_after_mistake'] += $result->won() ? 1 : 0;
            }

            if ($injected) {
                $turns[$report['turn']] = ($turns[$report['turn']] ?? 0) + 1;

                if (is_string($report['score_delta'])) {
                    $terminalBest++;
                } else {
                    $deltas[] = $report['score_delta'];
                }
            }

            if ($onMistakeGame !== null) {
                $onMistakeGame($this->mistakeRow($result, $baselineWon));
            }
        }

        ksort($turns);

        return $counts + [
            'recovery_rate' => $counts['eligible_mistake_games'] === 0
                ? null
                : round($counts['recoveries_after_mistake'] / $counts['eligible_mistake_games'], 4),
            'eligible_mistake_coverage' => $counts['planner_baseline_wins'] === 0
                ? null
                : round($counts['eligible_mistake_games'] / $counts['planner_baseline_wins'], 4),
            'avg_finite_score_delta' => $deltas === [] ? null : round(array_sum($deltas) / count($deltas), 4),
            'min_finite_score_delta' => $deltas === [] ? null : min($deltas),
            'mistakes_skipping_a_winning_move' => $terminalBest,
            'mistake_turns' => array_map('intval', $turns),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mistakeRow(SimulationResult $result, bool $baselineWon): array
    {
        $report = $result->strategyReport;
        $signature = static fn (?array $action): ?string => $action === null ? null : PlannerOneMistakeStrategy::signature($action);

        return [
            'level' => $result->levelId,
            'deck' => $result->deck,
            'scenario' => $result->scenario,
            'seed' => $result->seed,
            'planner_won' => $baselineWon,
            'won_after_mistake' => $result->won(),
            'status' => $report['status'] ?? null,
            'turn' => $report['turn'] ?? null,
            'best_signature' => $signature($report['best'] ?? null),
            'best_card' => $report['best']['card_id'] ?? null,
            'best_score' => $report['best']['score'] ?? null,
            'mistake_signature' => $signature($report['mistake'] ?? null),
            'mistake_card' => $report['mistake']['card_id'] ?? null,
            'mistake_score' => $report['mistake']['score'] ?? null,
            'score_delta' => $report['score_delta'] ?? null,
            'mistake_kind' => $report['mistake_kind'] ?? null,
            'intent_type' => $report['context']['intent_type'] ?? null,
            'intent_element' => $report['context']['intent_element'] ?? null,
            'shielded_elements' => isset($report['context']['shielded_elements'])
                ? implode('+', $report['context']['shielded_elements'])
                : null,
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
            'avg_level_phase_changes' => round(array_sum(array_map(static fn (SimulationResult $result): int => count($result->levelPhaseChanges), $results)) / max(1, $games), 3),
            'level_phases' => $this->levelPhaseReach($level, $results),
            'avg_metrics' => $metrics,
        ];
    }

    /**
     * 每一幕的到達率與平均進入回合。第一幕從第 1 回合開始，每一局都算到達。
     *
     * @param  list<SimulationResult>  $results
     * @return list<array{id: string, order: int, reach_rate: float, avg_entry_turn: float|null}>
     */
    private function levelPhaseReach(LevelDefinition $level, array $results): array
    {
        $games = max(1, count($results));

        return array_map(static function (LevelPhaseDefinition $phase, int $index) use ($results, $games): array {
            if ($index === 0) {
                return ['id' => $phase->id, 'order' => 1, 'reach_rate' => 1.0, 'avg_entry_turn' => 1.0];
            }

            $entries = [];

            foreach ($results as $result) {
                foreach ($result->levelPhaseChanges as $change) {
                    if ($change['to'] === $phase->id) {
                        // 切幕記在剛結束的回合，新幕從下一回合開始。
                        $entries[] = $change['turn'] + 1;
                    }
                }
            }

            return [
                'id' => $phase->id,
                'order' => $index + 1,
                'reach_rate' => round(count($entries) / $games, 4),
                'avg_entry_turn' => $entries === [] ? null : round(array_sum($entries) / count($entries), 3),
            ];
        }, $level->levelPhases, array_keys($level->levelPhases));
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
                // 每格等權平均的幕次到達率，順序同設定。
                'level_phase_reach' => array_map(
                    static fn (int $index): array => [
                        'id' => $mine[0]['level_phases'][$index]['id'],
                        'reach_rate' => round(array_sum(array_map(static fn (array $cell): float => $cell['level_phases'][$index]['reach_rate'], $mine)) / count($mine), 4),
                    ],
                    array_keys($mine[0]['level_phases']),
                ),
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
     * @return array<string, float|int|null>
     */
    private function difficulty(array $cells, array $strategies): array
    {
        $planner = $strategies['planner']['weighted_win_rate'] ?? null;
        $forecast = $strategies['forecast-aware']['weighted_win_rate'] ?? null;
        $unconditionalMistake = $strategies['planner-one-mistake']['weighted_win_rate'] ?? null;

        $mistakeCells = array_values(array_filter($cells, static fn (array $cell): bool => $cell['strategy'] === 'planner-one-mistake'));
        $eligible = array_sum(array_column($mistakeCells, 'eligible_mistake_games'));
        $baselineWins = array_sum(array_column($mistakeCells, 'planner_baseline_wins'));
        $recovery = $eligible === 0 ? null : array_sum(array_column($mistakeCells, 'recoveries_after_mistake')) / $eligible;

        $plannerCells = array_values(array_filter($cells, static fn (array $cell): bool => $cell['strategy'] === 'planner' && $cell['wins'] > 0));
        $winningWeight = array_sum(array_column($plannerCells, 'wins'));
        $budget = $winningWeight === 0 ? null : array_sum(array_map(
            static fn (array $cell): float => $cell['avg_winning_turn_budget_used'] * $cell['wins'],
            $plannerCells,
        )) / $winningWeight;

        $index = static fn (?float $third): ?float => ($planner === null || $forecast === null || $third === null || $budget === null)
            ? null
            : round(0.35 * (1 - $planner) + 0.25 * (1 - $forecast) + 0.25 * (1 - $third) + 0.15 * $budget, 4);

        return [
            'planner_win_rate' => $planner,
            'forecast_aware_win_rate' => $forecast,
            'one_mistake_recovery_rate' => $recovery === null ? null : round($recovery, 4),
            'planner_winning_turn_budget_used' => $budget === null ? null : round($budget, 4),
            'difficulty_index' => $index($recovery === null ? null : round($recovery, 4)),
            'planner_baseline_wins' => $baselineWins,
            'eligible_mistake_coverage' => $baselineWins === 0 ? null : round($eligible / $baselineWins, 4),
            'mistakes_injected' => array_sum(array_column($mistakeCells, 'mistakes_injected')),
            'eligible_mistake_games' => $eligible,
            'recoveries_after_mistake' => array_sum(array_column($mistakeCells, 'recoveries_after_mistake')),
            'no_eligible_mistake_games' => array_sum(array_column($mistakeCells, 'no_eligible_mistake_games')),
            'missed_interrupt_mistakes' => array_sum(array_column($mistakeCells, 'missed_interrupt_mistakes')),
            'walked_into_shield_mistakes' => array_sum(array_column($mistakeCells, 'walked_into_shield_mistakes')),
            // 無條件勝率只供參考：它把「沒有可注入失誤」的局也算進分母，不是恢復率。
            'one_mistake_unconditional_win_rate' => $unconditionalMistake,
            'difficulty_index_unconditional_variant' => $index($unconditionalMistake),
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

        $recovery = $this->difficulty($cells, $strategies)['one_mistake_recovery_rate'];

        foreach (self::TARGETS[$level->sequence] ?? [] as $name => [$low, $high]) {
            $rate = $name === 'one-mistake-recovery' ? $recovery : ($strategies[$name]['weighted_win_rate'] ?? null);
            $bands[$name] = [
                'target' => [$low, $high],
                'actual' => $rate,
                'within' => $rate === null ? null : ($rate >= $low && $rate <= $high),
            ];
        }

        $plannerFloor = array_values(array_map(
            static fn (array $cell): array => ['scenario' => $cell['scenario'], 'deck' => $cell['deck'], 'win_rate' => $cell['win_rate']],
            array_filter($cells, static fn (array $cell): bool => $cell['strategy'] === 'planner' && $cell['win_rate'] < self::PLANNER_CELL_FLOOR),
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
