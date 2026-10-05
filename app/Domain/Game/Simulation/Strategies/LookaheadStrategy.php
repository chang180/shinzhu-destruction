<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\Strategy;
use App\Domain\Game\TurnResult;
use Random\Randomizer;

/**
 * 只往前看一步的策略基底。
 *
 * 用真正的引擎試算每個合法行動，不自己複製傷害公式——規則只有一份。
 * 一步等於「本回合的預告」，那正是玩家在畫面上看得到的資訊；不做更深的搜尋，
 * 避免策略偷看玩家看不到的未來回合。
 */
abstract class LookaheadStrategy implements Strategy
{
    public function __construct(private readonly ScenarioModifiers $modifiers) {}

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        if ($engine->legalActions($state) === []) {
            return null;
        }

        return $this->pick($state, $this->ranked($state, $engine, $level));
    }

    /**
     * 從已排序的候選裡挑出要出的那一手。
     *
     * `choose()` 與需要先看整份排名的子類（`PlannerOneMistakeStrategy`）共用這一份，
     * 否則兩邊的取捨規則會各自漂移——P10-2.1 就因為子類自己取第一名而漏掉下面的
     * `-INF` 分支，在「每一種出牌都會輸」的回合打出和 planner 不同的牌。
     *
     * 候選為空，或每一種出牌都會輸（分數全是 `-INF`）時回蓄勢：既然都要輸，
     * 不如把資源留著。
     *
     * @param  list<array{0: array<string, mixed>, 1: float}>  $ranked
     * @return array<string, mixed>
     */
    protected function pick(BattleState $state, array $ranked): array
    {
        $best = $ranked[0] ?? null;

        if ($best === null || $best[1] === -INF) {
            return self::gather();
        }

        $action = $best[0];
        $action['keep'] = $this->keep($state, $action);

        return $action;
    }

    /**
     * 依分數由高到低排序的出牌；同分保留合法清單的原順序，和 choose() 的取捨一致。
     *
     * @return list<array{0: array<string, mixed>, 1: float}>
     */
    protected function ranked(BattleState $state, BattleEngine $engine, LevelDefinition $level): array
    {
        $scored = $this->evaluate($state, $engine, $level);
        $order = array_keys($scored);

        usort($order, static fn (int $a, int $b): int => [$scored[$b][1], $a] <=> [$scored[$a][1], $b]);

        return array_map(static fn (int $index): array => $scored[$index], $order);
    }

    /**
     * 用真正的引擎試算每個合法出牌並計分。
     *
     * @return list<array{0: array<string, mixed>, 1: float}>
     */
    private function evaluate(BattleState $state, BattleEngine $engine, LevelDefinition $level): array
    {
        $scored = [];

        foreach ($engine->legalActions($state) as $action) {
            // 換牌不結束回合，一步預測看不出它的價值；先只評估真正的出牌。
            if ($action['type'] !== 'play') {
                continue;
            }

            try {
                $result = $engine->apply(
                    $state,
                    new ActionRequest(
                        actionId: 'lookahead',
                        expectedVersion: $state->version,
                        type: ActionType::Play,
                        cardId: $action['card_id'],
                        fixedSkillId: $action['fixed'],
                    ),
                    $level,
                    $this->modifiers,
                );
            } catch (InvalidActionException) {
                continue;
            }

            $scored[] = [$action, $this->score($state, $result, $action)];
        }

        return $scored;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    abstract protected function score(BattleState $before, TurnResult $result, array $action): float;

    /**
     * 預設不留牌：多看新牌。會布局的策略覆寫這個方法。
     *
     * @param  array<string, mixed>  $action
     * @return list<string>
     */
    protected function keep(BattleState $state, array $action): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function gather(): array
    {
        return ['type' => 'play', 'card_id' => null, 'fixed' => 'gather', 'skill_id' => 'gather', 'target' => null];
    }

    /**
     * 本回合實際打進核心的量（城市回應之後的淨值）。
     */
    protected function coreDelta(BattleState $before, TurnResult $result): int
    {
        return $before->coreResilience - $result->state->coreResilience;
    }

    protected function hasEvent(TurnResult $result, string $type): bool
    {
        foreach ($result->events as $event) {
            if ($event->type === $type) {
                return true;
            }
        }

        return false;
    }
}
