<?php

namespace App\Console\Commands;

use App\Services\Game\DifficultyReport;
use Illuminate\Console\Command;

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
                            {--csv= : 把逐格結果寫成 CSV 檔}';

    protected $description = '以固定 seed 產生 P10 難度報告：關卡 × 情境 × 合法牌組 × 策略';

    public function handle(DifficultyReport $report): int
    {
        $suite = (string) $this->option('suite');

        if (! in_array($suite, DifficultyReport::SUITES, true)) {
            $this->components->error('suite 只能是 quick 或 full');

            return self::INVALID;
        }

        $seeds = max(1, (int) $this->option('seeds'));

        $result = $report->build(
            suite: $suite,
            seeds: $seeds,
            startSeed: (int) $this->option('start-seed'),
            levelIds: $this->option('level'),
            strategyNames: $this->option('strategy'),
            progress: fn (string $levelId) => $this->components->info("{$levelId} 完成"),
        );

        $this->renderLevels($result['levels']);
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

            $index = $level['difficulty']['difficulty_index'];
            $row[] = $index === null ? '—' : sprintf('%.4f', $index);
            $rows[] = $row;
        }

        $this->table(['關卡', '牌組', ...array_map(static fn (string $name): string => $name.' 加權（最低–最高）', $names), 'difficulty_index'], $rows);
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
                        '  %s %s 加權 %.1f%% 不在目標 %d–%d%%',
                        $level['level'],
                        $name,
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

            fputcsv($handle, [
                'level', 'sequence', 'deck', 'scenario', 'strategy', 'games', 'wins', 'win_rate',
                'avg_end_turn', 'avg_winning_turn_budget_used', 'avg_core_remaining_on_loss', 'illegal_choices',
                'avg_phase_changes', ...$metricKeys,
            ], escape: '');

            foreach ($result['cells'] as $cell) {
                fputcsv($handle, [
                    $cell['level'], $cell['sequence'], $cell['deck'], $cell['scenario'], $cell['strategy'],
                    $cell['games'], $cell['wins'], $cell['win_rate'], $cell['avg_end_turn'],
                    $cell['avg_winning_turn_budget_used'] ?? '', $cell['avg_core_remaining_on_loss'] ?? '',
                    $cell['illegal_choices'], $cell['avg_phase_changes'],
                    ...array_map(static fn (string $key): float => $cell['avg_metrics'][$key], $metricKeys),
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
