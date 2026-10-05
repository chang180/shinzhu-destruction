<?php

namespace Tests\Feature\Game;

use App\Domain\Game\LevelRepository;
use App\Domain\Game\Phases\LevelPhaseDefinition;
use App\Services\Game\DifficultyReport;
use Tests\TestCase;

/**
 * P10-7 全曲線驗收。
 *
 * 這個測試斷言的是**已交付的發布矩陣**（27 情境 × 合法牌組 × 300 seeds），不是在測試裡
 * 重跑它——重跑一次要數十分鐘，放進必要回歸只會讓人跳過它。代價是矩陣可能過期，所以
 * 每一項門檻之前先擋過期：規則版本、量尺版本、關卡清單與幕次都必須和目前的設定一致。
 * 依 .ai/rules/game.md，任何數值或公式變更都必須升 rules_version，所以改了平衡卻沒有
 * 重跑矩陣，這裡就會紅。
 *
 * 快速 9 情境矩陣仍然是另一道關（StrategyMatrixTest）。
 */
class DifficultyProgressionTest extends TestCase
{
    private const REPORT = 'docs/phase-reports/data/p10-7/release-full-300.json';

    private const SOLVER = 'docs/phase-reports/data/p10-7/solver-full-300.txt';

    /**
     * 唯一一項已知且已揭露的未達門檻：第 1 關的失誤恢復率高於目標上限。
     * 來源與理由見 docs/phase-reports/P10-2.1.md 與 P10-7 報告；門檻本身沒有放寬。
     * 任何其他未達項，或這一項消失／變成別的項目，都會讓測試失敗。
     */
    private const KNOWN_UNMET = ['empty-cup' => ['one-mistake-recovery']];

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        $path = base_path(self::REPORT);
        $this->assertFileExists($path, '發布矩陣不存在；請跑 game:difficulty-report --suite=full --seeds=300');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_release_matrix_measures_the_levels_this_build_actually_ships(): void
    {
        $report = $this->report();
        $levels = app(LevelRepository::class)->all();

        $this->assertSame(config('game.rules_version'), $report['meta']['rules_version'], '發布矩陣的規則版本和目前設定不一致：矩陣過期了');
        $this->assertSame(DifficultyReport::INDEX_VERSION, $report['meta']['index_version'], '難度量尺版本不一致：不同版本的 difficulty_index 不可比較');
        $this->assertSame('full', $report['meta']['suite']);
        $this->assertSame(300, $report['meta']['seeds_per_cell']);
        $this->assertCount(27, $report['meta']['scenarios']);

        $this->assertSame(array_keys($levels), array_column($report['levels'], 'level'), '矩陣涵蓋的關卡和目前的關卡清單不一致');

        foreach ($report['levels'] as $measured) {
            $level = $levels[$measured['level']];
            $this->assertSame($level->sequence, $measured['sequence'], $measured['level']);

            // 幕次是本輪的核心內容，必須逐關對上 id 與順序。
            $this->assertSame(
                array_map(static fn (LevelPhaseDefinition $phase): string => $phase->id, $level->levelPhases),
                array_column($measured['strategies']['planner']['level_phase_reach'], 'id'),
                "{$measured['level']} 的幕次和矩陣量測時不一樣"
            );
        }
    }

    public function test_difficulty_index_rises_at_every_step_of_the_five_levels(): void
    {
        $progression = $this->report()['progression'];

        $this->assertSame(0.05, $progression['required_step']);
        $this->assertCount(4, $progression['steps']);

        foreach ($progression['steps'] as $step) {
            $this->assertGreaterThanOrEqual(
                $progression['required_step'],
                $step['delta'],
                "{$step['from']} → {$step['to']} 的難度沒有上升足夠：{$step['delta']}"
            );
            $this->assertTrue($step['passes']);
        }

        $this->assertTrue($progression['monotonic_increasing'], '五關難度不是逐關遞增');
        $this->assertTrue($progression['passes']);
    }

    public function test_every_level_sits_in_its_target_bands_apart_from_the_one_disclosed_gap(): void
    {
        $unmet = [];

        foreach ($this->report()['levels'] as $level) {
            foreach ($level['gates']['bands'] as $name => $band) {
                $this->assertArrayHasKey('actual', $band, "{$level['level']} {$name} 缺少量測值");
                $this->assertCount(2, $band['target'], "{$level['level']} {$name} 缺少目標區間");

                if ($band['within'] !== true) {
                    $unmet[$level['level']][] = $name;
                }
            }
        }

        // 不放寬門檻：只容許已揭露的那一項，而且必須完全一致。
        $this->assertSame(self::KNOWN_UNMET, $unmet, '目標區間的未達項和已揭露的清單不一致');
    }

    public function test_no_single_cell_falls_below_the_planner_floor(): void
    {
        foreach ($this->report()['levels'] as $level) {
            $this->assertSame([], $level['gates']['planner_cells_below_55'], "{$level['level']} 有情境低於逐格下限");
            $this->assertGreaterThanOrEqual(
                DifficultyReport::PLANNER_CELL_FLOOR,
                $level['strategies']['planner']['min_cell']['win_rate'],
                "{$level['level']} 的最低格低於 planner 逐格下限"
            );
        }
    }

    public function test_weak_strategies_never_clear_a_level_often_enough_to_count_as_a_solution(): void
    {
        foreach ($this->report()['levels'] as $level) {
            $this->assertSame([], $level['gates']['weak_strategy_cells_at_or_above_50'], "{$level['level']} 有弱策略達到 50%");

            foreach (['random', 'single-water', 'legacy-cycle'] as $weak) {
                $this->assertLessThan(
                    $level['strategies']['planner']['weighted_win_rate'],
                    $level['strategies'][$weak]['weighted_win_rate'],
                    "{$level['level']} 的 {$weak} 不該和 planner 一樣有效"
                );
            }
        }
    }

    public function test_illegal_choices_never_happen_in_any_cell(): void
    {
        foreach ($this->report()['levels'] as $level) {
            foreach ($level['strategies'] as $name => $strategy) {
                $this->assertSame(0, $strategy['illegal_choices'], "{$level['level']} 的 {$name} 出現不合法選擇");
            }
        }
    }

    public function test_every_legal_reward_deck_is_measured_separately(): void
    {
        $byLevel = collect($this->report()['levels'])->keyBy('level');

        foreach ($byLevel as $levelId => $measured) {
            $this->assertNotEmpty($measured['decks'], $levelId);
            $this->assertSame(
                count(array_unique($measured['decks'])),
                count($measured['decks']),
                "{$levelId} 的牌組清單有重複"
            );
        }

        // 牌組差異必須逐副分開量測，不能只報合併值：第 2 關與第 4 關各給兩選一。
        $this->assertCount(1, $byLevel['empty-cup']['decks'], '第 1 關還沒有獎勵牌組');
        $this->assertCount(1, $byLevel['noon-fold']['decks'], '第 2 關的獎勵要到第 3 關才生效');
        $this->assertCount(2, $byLevel['meter-feast']['decks'], '第 3 關要分開量第 2 關的兩種獎勵');
        $this->assertCount(2, $byLevel['mirror-shade']['decks'], '第 4 關要分開量第 2 關的兩種獎勵');
        $this->assertCount(4, $byLevel['stored-night']['decks'], '第 5 關要分開量第 2 關 × 第 4 關的四種組合');
    }

    public function test_scheduled_acts_are_always_reached_and_conditional_acts_are_reported_not_assumed(): void
    {
        $levels = app(LevelRepository::class)->all();

        foreach ($this->report()['levels'] as $measured) {
            $level = $levels[$measured['level']];
            $reach = collect($measured['strategies']['planner']['level_phase_reach'])->keyBy('id');

            foreach ($level->levelPhases as $phase) {
                $rate = (float) $reach[$phase->id]['reach_rate'];

                if ($this->isConditional($phase)) {
                    // 條件幕依設計不保證到達：它只在這一局真的走到那個局面時才開。
                    // 這裡只要求它可達，不設人為下限——不會把目前的到達率寫成門檻來讓測試過關。
                    $this->assertGreaterThan(0.0, $rate, "{$measured['level']} 的條件幕 {$phase->id} 完全不可達");
                    $this->assertLessThanOrEqual(1.0, $rate);

                    continue;
                }

                // 有回合門檻的幕必須幾乎一定到達；到不了表示關卡在那之前就結束了。
                $this->assertGreaterThanOrEqual(
                    0.95,
                    $rate,
                    "{$measured['level']} 的排程幕 {$phase->id} 到達率只有 {$rate}"
                );
            }
        }
    }

    public function test_every_cell_of_the_release_matrix_is_provably_solvable(): void
    {
        $path = base_path(self::SOLVER);
        $this->assertFileExists($path, '全量可解性摘要不存在；請跑 game:solve 的 27 情境 × 300 seeds 掃描');

        $levels = app(LevelRepository::class)->all();
        $report = collect($this->report()['levels'])->keyBy('level');
        $expected = 0;

        foreach ($levels as $id => $level) {
            // 每一關的格數 = 合法牌組 × 27 情境，和發布矩陣用同一組牌組。
            $expected += count($report[$id]['decks']) * 27;
        }

        $cells = 0;
        $solved = 0;
        $unknown = 0;
        $unsolved = 0;

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (! str_starts_with($line, '|')) {
                continue;
            }

            $columns = array_map('trim', explode('|', trim($line, '|')));

            if (count($columns) !== 7 || ! ctype_digit($columns[3])) {
                continue;
            }

            $cells++;
            $solved += (int) $columns[3];
            $unknown += (int) $columns[4];
            $unsolved += (int) $columns[5];
        }

        // 格數對不上就表示摘要的格式變了或掃描不完整，寧可紅也不要假裝有涵蓋。
        $this->assertSame($expected, $cells, '可解性摘要的格數和發布矩陣的關卡 × 牌組 × 情境不一致');
        $this->assertSame(0, $unsolved, '有格被證明無解');
        // 預算用完只代表不確定，但發布不該靠「搜不完所以算過」。
        $this->assertSame(0, $unknown, '有格因為預算用完而不確定');
        $this->assertSame($expected * 300, $solved, '不是每一個 seed 都找到並重播驗證過通關路徑');
    }

    /** 和前端 isConditionalAct() 同一條規則：遞迴下去完全沒有回合門檻就是條件幕。 */
    private function isConditional(LevelPhaseDefinition $phase): bool
    {
        return $this->hasNoTurnThreshold($phase->startsWhen->toArray());
    }

    /**
     * @param  array<string, mixed>  $trigger
     */
    private function hasNoTurnThreshold(array $trigger): bool
    {
        if (($trigger['type'] ?? null) === 'turn_gte') {
            return false;
        }

        foreach ($trigger['of'] ?? [] as $inner) {
            if (! $this->hasNoTurnThreshold($inner)) {
                return false;
            }
        }

        return true;
    }
}
