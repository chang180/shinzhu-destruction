<?php

namespace App\Console\Commands;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\SimulationResult;
use App\Domain\Game\Simulation\Strategies\GreedyStrategy;
use App\Domain\Game\Simulation\Strategies\LegacyCycleStrategy;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Simulation\Strategies\RandomStrategy;
use App\Domain\Game\Simulation\Strategies\SingleElementStrategy;
use App\Domain\Game\Simulation\Strategy;
use Illuminate\Console\Command;

/**
 * 策略矩陣。每一格是（關卡 × 情境 × 策略 × seed）的固定重跑，
 * 用來驗收「至少兩種可行策略」與「固定循環不再保證成功」。
 */
class SimulateBattlesCommand extends Command
{
    protected $signature = 'game:simulate
                            {--level=* : 只跑指定關卡，預設全部}
                            {--seeds=100 : 每個組合的 seed 數}
                            {--start-seed=1 : 起始 seed}
                            {--json= : 把逐場結果寫成 JSON 檔}';

    protected $description = '以固定 seed 跑策略矩陣，輸出各關卡／情境／策略的勝率';

    public function handle(LevelRepository $levels, BattleEngine $engine): int
    {
        $simulator = new BattleSimulator($engine);
        $levelIds = $this->option('level') ?: $levels->ids();
        $seeds = (int) $this->option('seeds');
        $startSeed = (int) $this->option('start-seed');

        $scenarios = $this->scenarios();

        $rows = [];
        /** @var list<SimulationResult> $all */
        $all = [];

        foreach ($levelIds as $levelId) {
            $level = $levels->get($levelId);

            foreach ($scenarios as $scenarioLabel => $modifiers) {
                // 前瞻策略拿到的是本局實際的情境修正，就像玩家在戰前情報看到的一樣。
                foreach ($this->strategies($modifiers) as $strategy) {
                    $wins = 0;
                    $turns = 0;
                    $remaining = 0;
                    $rejected = 0;

                    for ($i = 0; $i < $seeds; $i++) {
                        $result = $simulator->run($level, $modifiers, $strategy, $startSeed + $i, $scenarioLabel);
                        $all[] = $result;

                        $wins += $result->won() ? 1 : 0;
                        $turns += $result->turns;
                        $remaining += $result->coreRemaining;
                        $rejected += $result->rejectedActions;
                    }

                    $rows[] = [
                        $levelId,
                        $scenarioLabel,
                        $strategy->name(),
                        sprintf('%5.1f%%', 100 * $wins / $seeds),
                        sprintf('%4.1f', $turns / $seeds),
                        sprintf('%4.1f', $remaining / $seeds),
                        $rejected,
                    ];
                }
            }
        }

        $this->table(
            ['關卡', '情境', '策略', '勝率', '平均回合', '平均剩餘韌性', '非法選擇'],
            $rows,
        );

        $path = $this->option('json');

        if ($path !== null && $path !== '') {
            file_put_contents($path, json_encode(
                array_map(static fn (SimulationResult $result): array => [
                    'level' => $result->levelId,
                    'scenario' => $result->scenario,
                    'strategy' => $result->strategy,
                    'seed' => $result->seed,
                    'outcome' => $result->outcome->value,
                    'turns' => $result->turns,
                    'core_remaining' => $result->coreRemaining,
                    'actions' => $result->actions,
                ], $all),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ));

            $this->components->info("逐場結果已寫入 {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * 低／中／高三組情境。P03 直接用修正上下界，不必依賴當日上游資料，
     * 確保矩陣可以在任何機器上重跑。
     *
     * @return array<string, ScenarioModifiers>
     */
    private function scenarios(): array
    {
        $limit = (float) config('game.scenario.modifier_limit');

        return [
            'low' => $this->uniform(-$limit),
            'mid' => $this->uniform(0.0),
            'high' => $this->uniform($limit),
        ];
    }

    private function uniform(float $value): ScenarioModifiers
    {
        $modifiers = [];
        $reasons = [];

        foreach (Element::all() as $element) {
            $modifiers[$element->value] = $value;
            $reasons[$element->value] = [
                'code' => 'simulation_bound',
                'message' => '模擬用的固定情境上下界，不是實際觀測',
                'inputs' => ['value' => $value],
            ];
        }

        return new ScenarioModifiers($modifiers, $reasons);
    }

    /**
     * @return list<Strategy>
     */
    private function strategies(ScenarioModifiers $modifiers): array
    {
        return [
            new RandomStrategy,
            new SingleElementStrategy(Element::Water),
            new LegacyCycleStrategy,
            new GreedyStrategy($modifiers),
            new PlannerStrategy($modifiers, app(CardCatalog::class)),
        ];
    }
}
