<?php

namespace App\Console\Commands;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Outcome;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Solver\SolvabilitySolver;
use App\Domain\Game\Solver\SolverResult;
use App\Domain\Game\Solver\SolverStatus;
use App\Services\Game\DifficultyReport;
use Illuminate\Console\Command;

/**
 * 離線可解性檢查（P10 §3.2 solver）。對每個（關卡, 情境, 合法牌組, seed）搜尋一條通關路徑，
 * 找到就重播驗證。和 game:difficulty-report 分開：solver 讀完整局面，不是玩家策略，不進 difficulty_index。
 */
class SolveLevelsCommand extends Command
{
    protected $signature = 'game:solve
                            {--level=* : 只跑指定關卡，預設全部}
                            {--scenario=* : 情境標籤（w?h?l?，見 game:difficulty-report），預設 w-h-l-、w0h0l0、w+h+l+}
                            {--deck=* : 只跑指定牌組標籤，預設該關全部合法牌組}
                            {--seeds=10 : 每格 seed 數}
                            {--start-seed=1 : 起始 seed}
                            {--budget=20000 : 每個 seed 最多展開的決策節點數}
                            {--reuse-paths : 先重播同牌組同 seed 已找到的路徑，失敗再搜尋}
                            {--json= : 把逐 seed 結果與通關路徑寫成 JSON 檔}';

    protected $description = '離線搜尋通關路徑，證明關卡在指定 seed 下有解（預算用完只回報未知，不寫成無解）';

    public function handle(LevelRepository $levels, DifficultyReport $report, BattleEngine $engine): int
    {
        $numbers = ['seeds' => 1, 'start-seed' => null, 'budget' => 1];

        foreach ($numbers as $name => $minimum) {
            $value = (string) $this->option($name);

            if (preg_match('/^-?\d+$/', $value) !== 1 || ($minimum !== null && (int) $value < $minimum)) {
                $this->components->error($minimum === null ? "{$name} 必須是整數" : "{$name} 必須是 ≥ {$minimum} 的整數");

                return self::INVALID;
            }
        }

        $allScenarios = $report->scenarios('full');
        $levelIds = array_values(array_unique($this->option('level'))) ?: $levels->ids();
        $scenarioLabels = array_values(array_unique($this->option('scenario'))) ?: ['w-h-l-', 'w0h0l0', 'w+h+l+'];
        $deckLabels = array_values(array_unique($this->option('deck')));

        $unknown = [
            'level' => array_values(array_filter($levelIds, static fn (string $id): bool => ! $levels->has($id))),
            'scenario' => array_values(array_diff($scenarioLabels, array_keys($allScenarios))),
        ];

        foreach ($unknown as $name => $values) {
            if ($values !== []) {
                $this->components->error("Unknown {$name} [".implode(', ', $values).'].');

                return self::INVALID;
            }
        }

        $knownDecks = [];

        foreach ($levelIds as $levelId) {
            $knownDecks = [...$knownDecks, ...array_keys($report->deckVariants($levels->get($levelId)))];
        }

        $missingDecks = array_values(array_diff($deckLabels, $knownDecks));

        if ($missingDecks !== []) {
            $this->components->error('Unknown deck ['.implode(', ', $missingDecks).'] for the selected levels.');

            return self::INVALID;
        }

        $solver = new SolvabilitySolver($engine);
        $seeds = (int) $this->option('seeds');
        $startSeed = (int) $this->option('start-seed');
        $budget = (int) $this->option('budget');
        $results = [];
        $rows = [];

        foreach ($levelIds as $levelId) {
            $level = $levels->get($levelId);

            foreach ($report->deckVariants($level) as $deckLabel => $composition) {
                if ($deckLabels !== [] && ! in_array($deckLabel, $deckLabels, true)) {
                    continue;
                }

                $verifiedPaths = [];

                foreach ($scenarioLabels as $scenarioLabel) {
                    $modifiers = $this->modifiers($allScenarios[$scenarioLabel]);
                    $counts = array_fill_keys(array_column(SolverStatus::cases(), 'value'), 0);
                    $nodes = 0;

                    for ($seed = $startSeed; $seed < $startSeed + $seeds; $seed++) {
                        $result = null;
                        $source = 'search';

                        if ($this->option('reuse-paths') && isset($verifiedPaths[$seed])) {
                            try {
                                if ($solver->replay($level, $modifiers, $seed, $composition, $verifiedPaths[$seed])->outcome === Outcome::PlayerVictory) {
                                    $result = new SolverResult(SolverStatus::Solved, $verifiedPaths[$seed], 0, $budget, 0, 0);
                                    $source = 'verified_cached_path';
                                }
                            } catch (InvalidActionException) {
                                // 情境改變可能讓原路徑提早結束或不再合法，必須重新搜尋。
                            }
                        }

                        $result ??= $solver->solve($level, $modifiers, $seed, $composition, $budget);
                        $replayed = $result->status === SolverStatus::Solved
                            ? $solver->replay($level, $modifiers, $seed, $composition, $result->path)->outcome === Outcome::PlayerVictory
                            : null;

                        if ($replayed === true) {
                            $verifiedPaths[$seed] = $result->path;
                        }

                        $counts[$result->status->value]++;
                        $nodes += $result->nodesExpanded;
                        $results[] = [
                            'level' => $levelId,
                            'deck' => $deckLabel,
                            'scenario' => $scenarioLabel,
                            'seed' => $seed,
                            'replay_verified' => $replayed,
                            'solution_source' => $source,
                        ] + $result->toArray();
                    }

                    $rows[] = [$levelId, $deckLabel, $scenarioLabel, ...array_values($counts), sprintf('%.1f', $nodes / $seeds)];
                }
            }
        }

        $this->table(['關卡', '牌組', '情境', ...array_column(SolverStatus::cases(), 'value'), '平均展開節點'], $rows);

        if (in_array(false, array_column($results, 'replay_verified'), true)) {
            $this->components->error('有路徑重播後沒有通關，solver 結果不可信。');

            return self::FAILURE;
        }

        $path = $this->option('json');

        if ($path !== null && $path !== '') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, json_encode([
                'meta' => [
                    'rules_version' => (string) config('game.rules_version'),
                    'budget' => $budget,
                    'seeds_per_cell' => $seeds,
                    'start_seed' => $startSeed,
                    'reuse_paths' => (bool) $this->option('reuse-paths'),
                    'reuse_contract' => 'Only same level/deck/seed paths are candidates. Every candidate is replayed under the new scenario; invalid or losing paths fall back to search. No solvability is inferred from another scenario.',
                    'action_model' => 'reveal; every legal play (cards and fixed actions) x every legal keep subset; one swap per turn; timeout',
                    'note' => 'exhausted_unknown means the node budget ran out; it is not a proof of no solution. Not a player strategy and not part of difficulty_index.',
                ],
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

            $this->components->info("逐 seed 結果已寫入 {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, float>  $values
     */
    private function modifiers(array $values): ScenarioModifiers
    {
        $reasons = array_map(static fn (float $value): array => [
            'code' => 'simulation_bound',
            'message' => '模擬用的固定情境上下界，不是實際觀測',
            'inputs' => ['value' => $value],
        ], $values);

        return new ScenarioModifiers($values, $reasons);
    }
}
