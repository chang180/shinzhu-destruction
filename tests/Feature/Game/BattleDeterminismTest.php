<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\Element;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Simulation\Strategies\RandomStrategy;
use Random\Randomizer;
use Tests\TestCase;

/**
 * 驗收：相同輸入得到相同事件與結局。
 *
 * rules_version 1.0.0 的城市完全沒有亂數，唯一的亂數來源是自動策略，
 * 所以 (關卡, 情境, 策略, seed) 完全決定整場結果。
 */
class BattleDeterminismTest extends TestCase
{
    private function modifiers(float $value): ScenarioModifiers
    {
        $modifiers = [];
        $reasons = [];

        foreach (Element::all() as $element) {
            $modifiers[$element->value] = $value;
            $reasons[$element->value] = ['code' => 'test', 'message' => '', 'inputs' => []];
        }

        return new ScenarioModifiers($modifiers, $reasons);
    }

    public function test_the_same_seed_replays_to_the_same_actions_and_outcome(): void
    {
        $levels = app(LevelRepository::class);
        $modifiers = $this->modifiers(0.0);

        foreach ($levels->ids() as $levelId) {
            $level = $levels->get($levelId);

            $first = (new BattleSimulator(app(BattleEngine::class)))
                ->run($level, $modifiers, new RandomStrategy, 4242, 'mid');
            $second = (new BattleSimulator(app(BattleEngine::class)))
                ->run($level, $modifiers, new RandomStrategy, 4242, 'mid');

            $this->assertSame($first->actions, $second->actions, "{$levelId} 的行動序列不一致");
            $this->assertSame($first->outcome, $second->outcome, "{$levelId} 的結局不一致");
            $this->assertSame($first->coreRemaining, $second->coreRemaining);
        }
    }

    public function test_a_replayed_action_list_reproduces_the_event_stream_byte_for_byte(): void
    {
        $level = app(LevelRepository::class)->get('meter-feast');
        $modifiers = $this->modifiers(0.0);

        $events = [];

        foreach ([1, 2] as $pass) {
            $engine = app(BattleEngine::class);
            $state = $engine->start($level);
            $strategy = new PlannerStrategy($modifiers);
            $stream = [];
            $counter = 0;

            while (! $state->outcome->isFinished()) {
                $choice = $strategy->choose($state, $engine, $level, new Randomizer);
                $result = $engine->apply(
                    $state,
                    new ActionRequest('r'.(++$counter), $state->version, $choice['skill_id'],
                        $choice['target'] === null ? null : Element::from($choice['target'])),
                    $level,
                    $modifiers,
                );
                $stream[] = $result->eventsToArray();
                $state = $result->state;
            }

            $events[$pass] = $stream;
        }

        $this->assertSame($events[1], $events[2]);
        $this->assertNotSame([], $events[1]);
    }

    public function test_different_data_scenarios_change_the_result(): void
    {
        $level = app(LevelRepository::class)->get('empty-cup');
        $simulator = new BattleSimulator(app(BattleEngine::class));
        $limit = (float) config('game.scenario.modifier_limit');

        $low = $simulator->run($level, $this->modifiers(-$limit), new RandomStrategy, 7, 'low');
        $high = $simulator->run($level, $this->modifiers($limit), new RandomStrategy, 7, 'high');

        /*
         * 同一個 seed 下，兩局的決策序列在共同長度內完全一致——差別只來自資料。
         * 高情境打得更痛，所以可能提早結束，行動數不一定相同。
         */
        $shared = min(count($low->actions), count($high->actions));

        $this->assertGreaterThan(0, $shared);
        $this->assertSame(
            array_slice(array_column($low->actions, 'skill_id'), 0, $shared),
            array_slice(array_column($high->actions, 'skill_id'), 0, $shared),
        );

        // 資料必須改變結果，不能只出現在頁尾（GAME-DESIGN §5）。
        $this->assertLessThan($low->coreRemaining, $high->coreRemaining);
    }
}
