<?php

namespace App\Console\Commands;

use App\Domain\Game\LevelDefinition;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\SimulationResult;
use App\Domain\Game\Simulation\Strategy;
use App\Models\Campaign;
use App\Services\Game\DeckComposer;
use App\Services\Game\DifficultyReport;
use Illuminate\Console\Command;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * P10-7 的試玩替代量測：一群「新手」模擬玩家從第 1 關依序往後打，每一關重試到過關為止。
 *
 * 這不是真人證據。它量得到的是嘗試次數、通關回合、失敗結束在哪一幕；量不到玩家
 * 是否看懂失敗原因、是否覺得後關更難——那兩項本來就是主觀的，只能由真人回答。
 * 刻意不使用 planner：那是完整前瞻的最佳解，拿它當新手會低估難度。
 */
class SimulatePlaytestCommand extends Command
{
    protected $signature = 'game:playtest
                            {--players=5 : 模擬玩家數（計畫要求至少 5 位）}
                            {--attempts=12 : 每一關最多重試幾次，超過就視為卡關}
                            {--seed=1 : 世代的起始 seed，決定每位玩家的技巧側寫、獎勵選擇與情境}
                            {--json= : 把逐玩家逐關結果寫成 JSON 檔}';

    protected $description = '以隨機模擬代替真人試玩：新手策略依序闖五關，記錄嘗試次數、通關回合與卡在哪一幕';

    /**
     * 新手側寫。都不是最佳解：前三個幾乎不讀預告，greedy 只看當下最強，
     * forecast-aware 會讀預告但沒有完整前瞻。
     */
    private const PROFILES = [
        'random' => '亂打：隨便出牌',
        'single-water' => '只打水系：抓到一招就重複',
        'legacy-cycle' => '固定循環：照舊習慣輪流出牌',
        'greedy' => '貪心：只看當下最強的一張',
        'forecast-aware' => '會讀預告：看城市下一步，但不做深層前瞻',
    ];

    public function handle(
        LevelRepository $levels,
        BattleSimulator $simulator,
        DifficultyReport $report,
        DeckComposer $decks,
    ): int {
        $players = max(1, (int) $this->option('players'));
        $attemptCap = max(1, (int) $this->option('attempts'));
        $baseSeed = (int) $this->option('seed');

        $scenarios = $report->scenarios('quick');
        $profiles = array_keys(self::PROFILES);
        $cohort = [];

        for ($i = 0; $i < $players; $i++) {
            $seed = $baseSeed + $i;
            $picker = new Randomizer(new Xoshiro256StarStar($seed));
            // 技巧側寫輪流指派，所以五位玩家一定涵蓋全部側寫，不會整組都是同一種打法。
            $profile = $profiles[$i % count($profiles)];
            $cohort[] = $this->playCampaign(
                $seed,
                $profile,
                $levels,
                $simulator,
                $report,
                $decks,
                $scenarios,
                $picker,
                $attemptCap,
            );
        }

        $this->render($cohort, $levels);

        $path = (string) $this->option('json');

        if ($path !== '') {
            $this->write($path, [
                'meta' => [
                    'rules_version' => config('game.rules_version'),
                    'players' => $players,
                    'attempt_cap' => $attemptCap,
                    'base_seed' => $baseSeed,
                    'profiles' => self::PROFILES,
                    'not_human_evidence' => '模擬策略不是真人。本檔沒有、也無法記錄玩家是否看懂失敗原因或主觀難度感受。',
                ],
                'players' => $cohort,
            ]);
            $this->components->info("試玩模擬已寫入 {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * 一位玩家的完整戰役：依序打、重試到過關，卡關就停在那一關（真人也走不下去）。
     *
     * @param  array<string, array<string, float>>  $scenarios
     * @return array<string, mixed>
     */
    private function playCampaign(
        int $seed,
        string $profile,
        LevelRepository $levels,
        BattleSimulator $simulator,
        DifficultyReport $report,
        DeckComposer $decks,
        array $scenarios,
        Randomizer $picker,
        int $attemptCap,
    ): array {
        $choices = [];
        $played = [];
        $stuckAt = null;

        foreach ($levels->all() as $level) {
            if (! $level->available) {
                continue;
            }

            $outcome = $this->playLevel($level, $profile, $simulator, $report, $decks, $scenarios, $picker, $attemptCap, $choices, $seed);
            $played[] = $outcome;

            if (! $outcome['cleared']) {
                // 沒過就不往後打，和真人一樣卡在這裡。
                $stuckAt = $level->id;
                break;
            }

            if ($level->reward !== null) {
                $options = array_keys($level->reward['options']);
                $choices[$level->id] = $options[$picker->getInt(0, count($options) - 1)];
            }
        }

        return [
            'seed' => $seed,
            'profile' => $profile,
            'profile_note' => self::PROFILES[$profile],
            'deck_choices' => $choices,
            'levels_cleared' => count(array_filter($played, static fn (array $row): bool => $row['cleared'])),
            'stuck_at' => $stuckAt,
            'levels' => $played,
        ];
    }

    /**
     * 一關：重試到過關或用完次數。每次重試換 seed 與情境，等於真人重開一局。
     *
     * @param  array<string, array<string, float>>  $scenarios
     * @param  array<string, string>  $choices
     * @return array<string, mixed>
     */
    private function playLevel(
        LevelDefinition $level,
        string $profile,
        BattleSimulator $simulator,
        DifficultyReport $report,
        DeckComposer $decks,
        array $scenarios,
        Randomizer $picker,
        int $attemptCap,
        array $choices,
        int $seed,
    ): array {
        $composition = $decks->compose($level, (new Campaign)->forceFill(['deck_choices' => $choices]));
        $deckLabel = $choices === [] ? 'starter' : implode('+', $choices);
        $labels = array_keys($scenarios);
        $attempts = [];

        for ($attempt = 1; $attempt <= $attemptCap; $attempt++) {
            $scenarioLabel = $labels[$picker->getInt(0, count($labels) - 1)];
            $runSeed = $seed * 1000 + $attempt;
            // 策略逐情境建：forecast-aware 要讀這一局的情境修正才能算預告。
            $modifiers = $this->modifiers($scenarios[$scenarioLabel]);
            $strategy = $this->strategy($report, $modifiers, $profile);
            $result = $simulator->run(
                $level,
                $modifiers,
                $strategy,
                $runSeed,
                $scenarioLabel,
                $composition,
                $deckLabel,
            );

            $attempts[] = [
                'attempt' => $attempt,
                'seed' => $runSeed,
                'scenario' => $scenarioLabel,
                'won' => $result->won(),
                'turns' => $result->turns,
                'core_remaining' => $result->coreRemaining,
                'ended_in_act' => $this->endingAct($level, $result),
            ];

            if ($result->won()) {
                break;
            }
        }

        $last = $attempts[count($attempts) - 1];

        return [
            'level_id' => $level->id,
            'sequence' => $level->sequence,
            'deck' => $deckLabel,
            'cleared' => $last['won'],
            'attempts_used' => count($attempts),
            'clearing_turn' => $last['won'] ? $last['turns'] : null,
            'max_turns' => $level->maxTurns,
            // 失敗結束在哪一幕：這是「卡在哪一幕」能被機器量到的部分。
            'failed_in_acts' => $this->failureActs($attempts),
            'attempts' => $attempts,
        ];
    }

    private function strategy(DifficultyReport $report, ScenarioModifiers $modifiers, string $profile): Strategy
    {
        foreach ($report->strategies($modifiers) as $strategy) {
            if ($strategy->name() === $profile) {
                return $strategy;
            }
        }

        throw new \RuntimeException("未知的側寫策略：{$profile}");
    }

    /** 這一局結束時在哪一幕：取最後一次換幕的目標，沒換過就是第一幕。 */
    private function endingAct(LevelDefinition $level, SimulationResult $result): ?string
    {
        $changes = $result->levelPhaseChanges;

        if ($changes !== []) {
            return $changes[count($changes) - 1]['to'];
        }

        $first = $level->levelPhases[0] ?? null;

        return $first?->id;
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @return array<string, int>
     */
    private function failureActs(array $attempts): array
    {
        $acts = [];

        foreach ($attempts as $attempt) {
            if ($attempt['won'] === true || $attempt['ended_in_act'] === null) {
                continue;
            }

            $acts[$attempt['ended_in_act']] = ($acts[$attempt['ended_in_act']] ?? 0) + 1;
        }

        return $acts;
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
     * @param  list<array<string, mixed>>  $cohort
     */
    private function render(array $cohort, LevelRepository $levels): void
    {
        $rows = [];

        foreach ($cohort as $player) {
            $rows[] = [
                $player['seed'],
                $player['profile'],
                $player['levels_cleared'],
                $player['stuck_at'] ?? '全部通關',
                implode(' ', array_map(
                    static fn (array $row): string => sprintf(
                        '%s:%s%s',
                        $row['sequence'],
                        $row['cleared'] ? $row['attempts_used'].'次' : '卡關',
                        $row['cleared'] ? "/第{$row['clearing_turn']}回" : '',
                    ),
                    $player['levels'],
                )),
            ];
        }

        $this->table(['seed', '側寫', '通關數', '卡在', '逐關（關:嘗試/通關回合）'], $rows);

        // 逐關聚合：嘗試次數是否隨關卡上升，是「後關更難」能被機器量到的部分。
        $perLevel = [];

        foreach ($cohort as $player) {
            foreach ($player['levels'] as $row) {
                $perLevel[$row['level_id']]['attempts'][] = $row['attempts_used'];
                $perLevel[$row['level_id']]['cleared'][] = $row['cleared'];

                foreach ($row['failed_in_acts'] as $act => $count) {
                    $perLevel[$row['level_id']]['acts'][$act] = ($perLevel[$row['level_id']]['acts'][$act] ?? 0) + $count;
                }
            }
        }

        $summary = [];

        foreach ($levels->all() as $level) {
            $data = $perLevel[$level->id] ?? null;

            if ($data === null) {
                $summary[] = [$level->sequence, $level->id, '—', '—', '沒有玩家打到這一關'];

                continue;
            }

            $reached = count($data['attempts']);
            $cleared = count(array_filter($data['cleared']));
            $acts = $data['acts'] ?? [];
            arsort($acts);

            $summary[] = [
                $level->sequence,
                $level->id,
                "{$cleared}/{$reached}",
                sprintf('%.1f', array_sum($data['attempts']) / $reached),
                implode('、', array_map(
                    static fn (int $count, string $act): string => "{$act} {$count}",
                    $acts,
                    array_keys($acts),
                )) ?: '沒有失敗紀錄',
            ];
        }

        $this->table(['序', '關卡', '通關/打到', '平均嘗試', '失敗結束的幕'], $summary);
        $this->components->warn('模擬策略不是真人：這裡沒有「是否看懂失敗原因」與主觀難度感受，那兩項仍需真人試玩。');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(string $path, array $payload): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }
}
