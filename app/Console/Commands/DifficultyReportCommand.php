<?php

namespace App\Console\Commands;

use App\Services\Game\DifficultyReport;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * P10 難度報告。和 game:simulate 不同，這裡量的是難度曲線：
 * 混合情境、玩家實際可能持有的獎勵牌組、會讀預告與會失誤一次的策略，
 * 以及逐關的 difficulty_index。只量測，不改任何規則。
 */
class DifficultyReportCommand extends Command
{
    protected $signature = 'game:difficulty-report
                            {--suite=quick : quick（9 情境）或 full（27 情境）}
                            {--seeds=100 : 每格 seed 數}
                            {--start-seed=1 : 起始 seed}
                            {--level=* : 只跑指定關卡，預設全部}
                            {--strategy=* : 只跑指定策略，預設全部（缺策略時不算 difficulty_index）}
                            {--json= : 把完整報告寫成 JSON 檔}
                            {--csv= : 把逐格結果寫成 CSV 檔}
                            {--mistakes-csv= : 把 planner-one-mistake 的逐局失誤紀錄寫成 CSV 檔}';

    protected $description = '以固定 seed 產生 P10 難度報告：關卡 × 情境 × 合法牌組 × 策略';

    public function handle(DifficultyReport $report): int
    {
        $seeds = (string) $this->option('seeds');
        $startSeed = (string) $this->option('start-seed');

        foreach (['seeds' => $seeds, 'start-seed' => $startSeed] as $name => $value) {
            if (preg_match('/^-?\d+$/', $value) !== 1) {
                $this->components->error("{$name} 必須是整數");

                return self::INVALID;
            }
        }

        $mistakes = null;
        $mistakesPath = $this->option('mistakes-csv');

        try {
            $result = $report->build(
                suite: (string) $this->option('suite'),
                seeds: (int) $seeds,
                startSeed: (int) $startSeed,
                // 重複指定同一關或同一策略視為一次，避免同一格被跑兩次、報告重複列出。
                levelIds: array_values(array_unique($this->option('level'))),
                strategyNames: array_values(array_unique($this->option('strategy'))),
                progress: fn (string $levelId) => $this->components->info("{$levelId} 完成"),
                onMistakeGame: $mistakesPath === null || $mistakesPath === '' ? null : function (array $row) use (&$mistakes, $mistakesPath): void {
                    if ($mistakes === null) {
                        $this->ensureDirectory($mistakesPath);
                        $mistakes = fopen($mistakesPath, 'w');
                        fputcsv($mistakes, DifficultyReport::MISTAKE_COLUMNS, escape: '');
                    }

                    fputcsv($mistakes, array_map(static fn (mixed $value): string => match (true) {
                        $value === null => '',
                        is_bool($value) => $value ? 'true' : 'false',
                        default => (string) $value,
                    }, $row), escape: '');
                },
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        if ($mistakes !== null) {
            fclose($mistakes);
            $this->components->info("逐局失誤紀錄已寫入 {$mistakesPath}");
        }

        $this->renderLevels($result['levels']);
        $this->renderLevelPhases($result['levels']);
        $this->renderProgression($result['progression']);
        $this->renderGates($result['levels']);
        $this->write($result);

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $levels
     */
    private function renderLevels(array $levels): void
    {
        $names = [];

        foreach ($levels as $level) {
            $names = array_values(array_unique([...$names, ...array_keys($level['strategies'])]));
        }

        $rows = [];

        foreach ($levels as $level) {
            $row = [$level['sequence'].' '.$level['level'], count($level['decks'])];

            foreach ($names as $name) {
                $strategy = $level['strategies'][$name] ?? null;
                $row[] = $strategy === null ? '—' : sprintf(
                    '%5.1f%% (%d–%d)',
                    100 * $strategy['weighted_win_rate'],
                    (int) round(100 * $strategy['min_cell']['win_rate']),
                    (int) round(100 * $strategy['max_cell']['win_rate']),
                );
            }

            $difficulty = $level['difficulty'];
            $row[] = $difficulty['one_mistake_recovery_rate'] === null ? '—' : sprintf(
                '%5.1f%% (%d/%d)',
                100 * $difficulty['one_mistake_recovery_rate'],
                $difficulty['recoveries_after_mistake'],
                $difficulty['eligible_mistake_games'],
            );
            $row[] = $difficulty['difficulty_index'] === null ? '—' : sprintf('%.4f', $difficulty['difficulty_index']);
            $rows[] = $row;
        }

        $this->table(['關卡', '牌組', ...array_map(static fn (string $name): string => $name.' 加權（最低–最高）', $names), '失誤恢復（配對）', 'difficulty_index'], $rows);
    }

    /**
     * 多幕關卡的 planner 幕次到達率；單一幕的關卡不列。
     *
     * @param  list<array<string, mixed>>  $levels
     */
    private function renderLevelPhases(array $levels): void
    {
        foreach ($levels as $level) {
            $reach = $level['strategies']['planner']['level_phase_reach'] ?? [];

            if (count($reach) > 1) {
                $this->line(sprintf('  %s 幕次到達（planner）：%s', $level['level'], implode(' → ', array_map(
                    static fn (array $phase): string => sprintf('%s %.1f%%', $phase['id'], 100 * $phase['reach_rate']),
                    $reach,
                ))));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $progression
     */
    private function renderProgression(array $progression): void
    {
        foreach ($progression['steps'] as $step) {
            $this->line(sprintf(
                '  %s → %s：Δ %s %s',
                $step['from'],
                $step['to'],
                $step['delta'] === null ? '—' : sprintf('%+.4f', $step['delta']),
                match ($step['passes']) {
                    true => '通過',
                    false => '未達 +0.05',
                    null => '無法計算',
                },
            ));
        }

        $this->line('  逐關遞增（每步 ≥ +0.05）：'.match ($progression['passes']) {
            true => '通過',
            false => '不成立',
            null => '無法判定',
        });
    }

    /**
     * @param  list<array<string, mixed>>  $levels
     */
    private function renderGates(array $levels): void
    {
        foreach ($levels as $level) {
            foreach ($level['gates']['bands'] as $name => $band) {
                if ($band['within'] === false) {
                    $this->line(sprintf(
                        '  %s %s %s %.1f%% 不在目標 %d–%d%%',
                        $level['level'],
                        $name,
                        $name === 'one-mistake-recovery' ? '配對' : '加權',
                        100 * $band['actual'],
                        (int) round(100 * $band['target'][0]),
                        (int) round(100 * $band['target'][1]),
                    ));
                }
            }

            foreach ($level['gates']['planner_cells_below_55'] as $cell) {
                $this->line(sprintf('  %s planner 低於 55%%：%s／%s %.1f%%', $level['level'], $cell['scenario'], $cell['deck'], 100 * $cell['win_rate']));
            }

            foreach ($level['gates']['weak_strategy_cells_at_or_above_50'] as $cell) {
                $this->line(sprintf('  %s %s ≥ 50%%：%s／%s %.1f%%', $level['level'], $cell['strategy'], $cell['scenario'], $cell['deck'], 100 * $cell['win_rate']));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function write(array $result): void
    {
        $json = $this->option('json');

        if ($json !== null && $json !== '') {
            $this->ensureDirectory($json);
            file_put_contents($json, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n");
            $this->components->info("完整報告已寫入 {$json}");
        }

        $csv = $this->option('csv');

        if ($csv !== null && $csv !== '') {
            $this->ensureDirectory($csv);
            $handle = fopen($csv, 'w');
            $metricKeys = array_keys($result['cells'][0]['avg_metrics'] ?? []);
            $diagnosticKeys = array_keys($result['cells'][0]['mechanic_diagnostics'] ?? []);
            $recoveryKeys = [
                'planner_baseline_wins', 'mistakes_injected', 'eligible_mistake_games', 'recoveries_after_mistake',
                'no_eligible_mistake_games', 'missed_interrupt_mistakes', 'walked_into_shield_mistakes',
                'recovery_rate', 'eligible_mistake_coverage', 'avg_finite_score_delta', 'min_finite_score_delta',
                'mistakes_skipping_a_winning_move',
            ];

            $phaseKeys = [];

            for ($act = 1; $act <= DifficultyReport::CSV_LEVEL_PHASES; $act++) {
                array_push($phaseKeys, "act_{$act}_id", "act_{$act}_reach_rate", "act_{$act}_avg_entry_turn");
            }

            fputcsv($handle, [
                'level', 'sequence', 'deck', 'scenario', 'strategy', 'games', 'wins', 'win_rate',
                'avg_end_turn', 'avg_winning_turn_budget_used', 'avg_core_remaining_on_loss', 'illegal_choices',
                'avg_phase_changes', ...$metricKeys, ...$recoveryKeys, 'avg_level_phase_changes', ...$phaseKeys,
                ...array_map(static fn (string $key): string => 'diagnostic_'.$key, $diagnosticKeys),
            ], escape: '');

            foreach ($result['cells'] as $cell) {
                fputcsv($handle, [
                    $cell['level'], $cell['sequence'], $cell['deck'], $cell['scenario'], $cell['strategy'],
                    $cell['games'], $cell['wins'], $cell['win_rate'], $cell['avg_end_turn'],
                    $cell['avg_winning_turn_budget_used'] ?? '', $cell['avg_core_remaining_on_loss'] ?? '',
                    $cell['illegal_choices'], $cell['avg_phase_changes'],
                    ...array_map(static fn (string $key): float => $cell['avg_metrics'][$key], $metricKeys),
                    ...array_map(static fn (string $key): string => (string) ($cell[$key] ?? ''), $recoveryKeys),
                    $cell['avg_level_phase_changes'],
                    ...array_merge(...array_map(static function (int $index) use ($cell): array {
                        $phase = $cell['level_phases'][$index] ?? null;

                        return $phase === null ? ['', '', ''] : [$phase['id'], (string) $phase['reach_rate'], (string) ($phase['avg_entry_turn'] ?? '')];
                    }, range(0, DifficultyReport::CSV_LEVEL_PHASES - 1))),
                    ...array_map(static fn (string $key): string => (string) ($cell['mechanic_diagnostics'][$key] ?? ''), $diagnosticKeys),
                ], escape: '');
            }

            fclose($handle);
            $this->components->info("逐格結果已寫入 {$csv}");
        }
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
