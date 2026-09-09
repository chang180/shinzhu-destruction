<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleState;
use App\Domain\Game\Exceptions\InvalidActionException;
use Tests\TestCase;

/**
 * 手牌規則：抽牌、留牌、換牌、洗牌（P04-REVISION-PLAN §4）。
 *
 * 這些測試不看傷害，只看牌去了哪裡——牌組總數必須永遠守恆，
 * 否則「牌組 15 張」就只是文件上的數字。
 */
class CardHandTest extends TestCase
{
    use PlaysCards;

    private function totalCards(BattleState $state): int
    {
        return count($state->hand) + count($state->drawPile) + count($state->discardPile);
    }

    public function test_the_level_deck_expands_into_fifteen_distinct_physical_cards(): void
    {
        $state = $this->startState();

        $this->assertCount(15, $state->deck);
        $this->assertSame(15, $this->totalCards($state));
        // 同招的兩張卡是兩張不同的牌，各有自己的 ID。
        $this->assertGreaterThan(1, count(array_keys($state->deck, 'long-flow', true)));
    }

    public function test_revealing_draws_a_full_hand_and_opens_the_decision_window(): void
    {
        $state = $this->startState();

        $this->assertSame(BattleState::PHASE_AWAITING_REVEAL, $state->turnPhase);
        $this->assertSame([], $state->hand);

        $revealed = $this->reveal($state, null, '2026-09-09T00:00:30.000Z');

        $this->assertSame(BattleState::PHASE_DECISION, $revealed->turnPhase);
        $this->assertCount(5, $revealed->hand);
        $this->assertSame('2026-09-09T00:00:30.000Z', $revealed->deadlineAt);
        $this->assertSame(15, $this->totalCards($revealed));
    }

    public function test_revealing_twice_is_rejected_so_the_window_cannot_be_reopened(): void
    {
        $state = $this->reveal($this->startState());

        $this->expectException(InvalidActionException::class);
        $this->apply($state, new ActionRequest('again', $state->version, ActionType::Reveal));
    }

    public function test_keeping_cards_carries_them_into_the_next_hand_and_discards_the_rest(): void
    {
        $state = $this->reveal($this->startState());
        $played = $state->hand[0];
        $kept = [$state->hand[1], $state->hand[2]];

        $result = $this->apply($state, new ActionRequest(
            actionId: 'play',
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $played,
            keep: $kept,
        ));

        $after = $result->state;

        $this->assertSame($kept, $after->hand);
        $this->assertContains($played, $after->discardPile);
        $this->assertSame(15, $this->totalCards($after));

        // 留下的牌佔住下一手的位置：只補到 5 張，不是額外多拿。
        $next = $this->reveal($after);
        $this->assertCount(5, $next->hand);
        $this->assertSame($kept, array_slice($next->hand, 0, 2));
    }

    public function test_the_card_that_was_played_cannot_also_be_kept(): void
    {
        $state = $this->reveal($this->startState());
        $played = $state->hand[0];

        $this->expectExceptionMessage('要留下的牌必須是本回合未打出的手牌');
        $this->apply($state, new ActionRequest(
            actionId: 'play',
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $played,
            keep: [$played],
        ));
    }

    public function test_keeping_more_than_the_limit_is_rejected(): void
    {
        $state = $this->reveal($this->startState());

        try {
            $this->apply($state, new ActionRequest(
                actionId: 'play',
                expectedVersion: $state->version,
                type: ActionType::Play,
                cardId: $state->hand[0],
                keep: [$state->hand[1], $state->hand[2], $state->hand[3]],
            ));
            $this->fail('留牌超過上限應該被拒絕');
        } catch (InvalidActionException $exception) {
            $this->assertSame('keep_limit_exceeded', $exception->reasonCode);
        }
    }

    public function test_a_swap_replaces_one_card_without_advancing_the_turn_or_resetting_the_deadline(): void
    {
        $state = $this->reveal($this->startState(), null, '2026-09-09T00:00:30.000Z');
        $target = $state->hand[2];
        $turnBefore = $state->turn;

        $after = $this->apply($state, new ActionRequest(
            actionId: 'swap',
            expectedVersion: $state->version,
            type: ActionType::Swap,
            cardId: $target,
        ))->state;

        $this->assertCount(5, $after->hand);
        $this->assertNotContains($target, $after->hand);
        // 換掉的牌先離開可抽集合，所以不可能立刻被換回來。
        $this->assertContains($target, $after->discardPile);
        $this->assertSame($turnBefore, $after->turn);
        $this->assertSame('2026-09-09T00:00:30.000Z', $after->deadlineAt);
        $this->assertTrue($after->swapUsed);
        $this->assertSame(15, $this->totalCards($after));
    }

    public function test_only_one_free_swap_per_turn(): void
    {
        $state = $this->reveal($this->startState());

        $after = $this->apply($state, new ActionRequest(
            actionId: 'swap-1',
            expectedVersion: $state->version,
            type: ActionType::Swap,
            cardId: $state->hand[0],
        ))->state;

        try {
            $this->apply($after, new ActionRequest(
                actionId: 'swap-2',
                expectedVersion: $after->version,
                type: ActionType::Swap,
                cardId: $after->hand[0],
            ));
            $this->fail('一回合只能免費換一次');
        } catch (InvalidActionException $exception) {
            $this->assertSame('swap_already_used', $exception->reasonCode);
        }
    }

    public function test_the_swap_allowance_returns_on_the_next_turn(): void
    {
        $state = $this->reveal($this->startState());

        $swapped = $this->apply($state, new ActionRequest(
            actionId: 'swap-1',
            expectedVersion: $state->version,
            type: ActionType::Swap,
            cardId: $state->hand[0],
        ))->state;

        $played = $this->apply($swapped, new ActionRequest(
            actionId: 'play',
            expectedVersion: $swapped->version,
            type: ActionType::Play,
            cardId: $swapped->hand[0],
        ))->state;

        $this->assertFalse($played->swapUsed);
    }

    public function test_the_draw_pile_reshuffles_from_the_discard_only_when_it_runs_out(): void
    {
        $state = $this->reveal($this->startState());
        $shuffles = $state->shuffleCount;

        // 15 張牌、每回合抽 5 張：第三回合揭牌時抽牌堆一定見底。
        for ($turn = 1; $turn <= 3; $turn++) {
            $state = $this->reveal($state);
            $state = $this->apply($state, new ActionRequest(
                actionId: 'play-'.$turn,
                expectedVersion: $state->version,
                type: ActionType::Play,
                cardId: null,
                fixedSkillId: 'gather',
            ))->state;
        }

        $state = $this->reveal($state);

        $this->assertGreaterThan($shuffles, $state->shuffleCount);
        $this->assertCount(5, $state->hand);
        $this->assertSame(15, $this->totalCards($state));
    }

    public function test_a_card_cannot_be_played_before_the_hand_is_revealed(): void
    {
        $state = $this->startState();
        $instance = array_key_first($state->deck);

        try {
            $this->apply($state, new ActionRequest(
                actionId: 'early',
                expectedVersion: $state->version,
                type: ActionType::Play,
                cardId: $instance,
            ));
            $this->fail('揭牌前不應該可以出牌');
        } catch (InvalidActionException $exception) {
            $this->assertSame('hand_not_revealed', $exception->reasonCode);
        }
    }

    public function test_two_copies_of_the_same_card_do_not_bypass_the_shared_cooldown(): void
    {
        $state = $this->reveal($this->startState());
        $state = $this->stack($state, 'probe.water');
        $first = $this->instanceFor($state, 'probe.water');

        // 手上同時有同招的兩張實體牌。
        $second = collect(array_keys($state->deck))
            ->first(fn (string $id): bool => $id !== $first && $state->deck[$id] === $state->deck[$first]);

        $state->drawPile = array_values(array_filter($state->drawPile, static fn (string $id): bool => $id !== $second));
        $state->discardPile = array_values(array_filter($state->discardPile, static fn (string $id): bool => $id !== $second));
        $state->hand = array_values(array_filter($state->hand, static fn (string $id): bool => $id !== $second));
        $state->hand[] = $second;

        // 試探沒有冷卻，所以改用有冷卻的破陣驗這件事。
        $state = $this->stack($state, 'breach.water');
        $breachFirst = $this->instanceFor($state, 'breach.water');

        $after = $this->apply($state, new ActionRequest(
            actionId: 'breach-1',
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $breachFirst,
        ))->state;

        $after = $this->stack($this->reveal($after), 'breach.water');

        try {
            $this->apply($after, new ActionRequest(
                actionId: 'breach-2',
                expectedVersion: $after->version,
                type: ActionType::Play,
                cardId: $this->instanceFor($after, 'breach.water'),
            ));
            $this->fail('同招的另一張牌不應該繞過冷卻');
        } catch (InvalidActionException $exception) {
            $this->assertSame('on_cooldown', $exception->reasonCode);
        }
    }

    public function test_the_public_state_hides_the_draw_pile_order_but_shows_its_size(): void
    {
        $state = $this->reveal($this->startState());
        $public = $state->toPublicArray();

        $this->assertArrayNotHasKey('draw_pile', $public);
        $this->assertArrayNotHasKey('deck_seed', $public);
        $this->assertSame(count($state->drawPile), $public['draw_pile_count']);
        $this->assertSame($state->hand, $public['hand']);
        $this->assertSame($state->discardPile, $public['discard_pile']);
    }
}
