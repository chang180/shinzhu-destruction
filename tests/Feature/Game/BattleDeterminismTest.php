<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Simulation\Strategies\RandomStrategy;
use App\Domain\Game\SkillKind;
use Random\Randomizer;
use Tests\TestCase;

/**
 * 驗收：相同輸入得到相同事件與結局。
 *
 * 城市完全沒有亂數；亂數只有牌序與策略選擇，兩者都由 seed 決定，
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
        $level = app(LevelRepository::class)->get('empty-cup');
        $modifiers = $this->modifiers(0.0);
        $seed = 991;

        $events = [];

        foreach ([1, 2] as $pass) {
            $engine = app(BattleEngine::class);
            $state = $engine->start($level, $seed);
            $strategy = new PlannerStrategy($modifiers, app(CardCatalog::class));
            $stream = [];
            $counter = 0;

            while (! $state->outcome->isFinished()) {
                $action = $state->turnPhase === BattleState::PHASE_AWAITING_REVEAL
                    ? new ActionRequest('r'.(++$counter), $state->version, ActionType::Reveal)
                    : $this->playRequest('r'.(++$counter), $state, $strategy->choose($state, $engine, $level, new Randomizer));

                $result = $engine->apply($state, $action, $level, $modifiers);
                $stream[] = $result->eventsToArray();
                $state = $result->state;
            }

            $events[$pass] = $stream;
        }

        $this->assertSame($events[1], $events[2]);
        $this->assertNotSame([], $events[1]);
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function playRequest(string $actionId, BattleState $state, array $choice): ActionRequest
    {
        return new ActionRequest(
            actionId: $actionId,
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $choice['card_id'] ?? null,
            fixedSkillId: $choice['fixed'] ?? null,
            keep: $choice['keep'] ?? [],
        );
    }

    public function test_the_same_seed_deals_the_same_hand_and_a_different_seed_does_not(): void
    {
        $engine = app(BattleEngine::class);
        $level = app(LevelRepository::class)->get('empty-cup');
        $modifiers = $this->modifiers(0.0);

        $hand = function (int $seed) use ($engine, $level, $modifiers): array {
            $state = $engine->start($level, $seed);

            return $engine->apply(
                $state,
                new ActionRequest('reveal', $state->version, ActionType::Reveal),
                $level,
                $modifiers,
            )->state->hand;
        };

        // 重整頁面不會換手牌：同一個 seed 一定發同一手。
        $this->assertSame($hand(555), $hand(555));
        $this->assertNotSame($hand(555), $hand(556));

        // 首手保證看得到三系試探（P04-REVISION-PLAN §4.5）。
        $cards = app(CardCatalog::class);
        $deck = $engine->start($level, 555)->deck;
        $elements = [];

        foreach ($hand(555) as $instanceId) {
            $skill = $cards->skillFor($deck[$instanceId]);

            if ($skill->kind === SkillKind::Probe) {
                $elements[] = $skill->element?->value;
            }
        }

        $this->assertSame(Element::values(), array_values(array_unique(array_filter($elements))));
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
