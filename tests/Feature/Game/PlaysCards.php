<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleEvent;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\TurnResult;
use RuntimeException;

/**
 * 規則測試用的發牌控制。
 *
 * 這些測試量的是傷害、抗性、破綻與打斷這些規則，不是抽牌運氣，所以 `act()`
 * 直接把要驗的那張牌放進手牌。它改的只是手牌，不碰任何數值或結算順序——
 * 牌序本身由 CardHandTest 與 BattleDeterminismTest 專門驗證。
 */
trait PlaysCards
{
    protected function engine(): BattleEngine
    {
        return app(BattleEngine::class);
    }

    protected function cards(): CardCatalog
    {
        return app(CardCatalog::class);
    }

    protected function level(string $id = 'empty-cup'): LevelDefinition
    {
        return app(LevelRepository::class)->get($id);
    }

    protected function startState(?LevelDefinition $level = null, int $seed = 20260909): BattleState
    {
        return $this->engine()->start($level ?? $this->level(), $seed);
    }

    /**
     * 揭牌並打出指定招式的一張牌。回傳 [結算後局面, 事件]。
     *
     * @return array{0: BattleState, 1: list<BattleEvent>}
     */
    protected function act(
        BattleState $state,
        string $skillId,
        ?LevelDefinition $level = null,
        array $keep = [],
    ): array {
        $level ??= $this->level();
        $state = $this->reveal($state, $level);
        $fixed = in_array($skillId, config('game.fixed_actions'), true);
        $state = $fixed ? $state : $this->stack($state, $skillId);

        $result = $this->apply($state, new ActionRequest(
            actionId: 'act-'.$state->version,
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $fixed ? null : $this->instanceFor($state, $skillId),
            fixedSkillId: $fixed ? $skillId : null,
            keep: $keep,
        ), $level);

        return [$result->state, $result->events];
    }

    /**
     * 開始回合。已經揭牌就原樣回傳，所以呼叫端不必自己追蹤階段。
     */
    protected function reveal(BattleState $state, ?LevelDefinition $level = null, ?string $deadlineAt = null): BattleState
    {
        if ($state->turnPhase !== BattleState::PHASE_AWAITING_REVEAL) {
            return $state;
        }

        return $this->apply($state, new ActionRequest(
            actionId: 'reveal-'.$state->version,
            expectedVersion: $state->version,
            type: ActionType::Reveal,
            deadlineAt: $deadlineAt,
        ), $level)->state;
    }

    protected function apply(BattleState $state, ActionRequest $action, ?LevelDefinition $level = null): TurnResult
    {
        return $this->engine()->apply($state, $action, $level ?? $this->level(), ScenarioModifiers::neutral());
    }

    /**
     * 把對應招式的一張實體牌放進手牌，需要時從抽牌堆或棄牌堆調回來。
     */
    protected function stack(BattleState $state, string $skillId): BattleState
    {
        $state = $state->copy();
        $instance = $this->instanceFor($state, $skillId);

        if ($state->inHand($instance)) {
            return $state;
        }

        $remove = static fn (array $pile): array => array_values(array_filter(
            $pile,
            static fn (string $id): bool => $id !== $instance,
        ));

        $state->drawPile = $remove($state->drawPile);
        $state->discardPile = $remove($state->discardPile);

        if (count($state->hand) >= $state->handSize) {
            $state->discardPile[] = array_pop($state->hand);
        }

        $state->hand[] = $instance;

        return $state;
    }

    /**
     * 這一局的牌組裡，第一張使用指定招式的實體牌。
     */
    protected function instanceFor(BattleState $state, string $skillId): string
    {
        foreach ($state->deck as $instanceId => $cardType) {
            if ($this->cards()->skillFor($cardType)->id === $skillId) {
                return $instanceId;
            }
        }

        throw new RuntimeException("這一局的牌組裡沒有使用 {$skillId} 的牌");
    }
}
