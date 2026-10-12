<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleState;
use App\Domain\Game\Exceptions\InvalidActionException;
use Tests\TestCase;

/**
 * 手牌規則：抽牌、手牌留存、換牌、洗牌（P04-REVISION-PLAN §4）。
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
        $this->assertCount($revealed->handSize, $revealed->hand);
        $this->assertSame('2026-09-09T00:00:30.000Z', $revealed->deadlineAt);
        $this->assertSame(15, $this->totalCards($revealed));
    }

    public function test_revealing_twice_is_rejected_so_the_window_cannot_be_reopened(): void
    {
        $state = $this->reveal($this->startState());

        $this->expectException(InvalidActionException::class);
        $this->apply($state, new ActionRequest('again', $state->version, ActionType::Reveal));
    }

    public function test_only_the_played_card_leaves_the_hand_and_the_rest_stay(): void
    {
        $state = $this->reveal($this->startState());
        $played = $state->hand[0];
        $stayed = array_slice($state->hand, 1);

        $after = $this->apply($state, new ActionRequest(
            actionId: 'play',
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $played,
        ))->state;

        $this->assertSame($stayed, $after->hand);
        $this->assertSame([$played], $after->discardPile);
        $this->assertSame(15, $this->totalCards($after));

        // 揭牌只補到上限：四張原手牌留著，只抽一張新牌。
        $next = $this->reveal($after);
        $this->assertCount($next->handSize, $next->hand);
        $this->assertSame($stayed, array_slice($next->hand, 0, $next->handSize - 1));
    }

    public function test_a_fixed_action_costs_no_card_and_leaves_a_full_hand(): void
    {
        $state = $this->reveal($this->startState());
        $before = $state->hand;

        $after = $this->apply($state, new ActionRequest(
            actionId: 'gather',
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: null,
            fixedSkillId: 'gather',
        ))->state;

        $this->assertSame($before, $after->hand);
        $this->assertSame([], $after->discardPile);

        $next = $this->reveal($after);
        $this->assertSame($before, $next->hand);
    }

    public function test_a_timeout_keeps_the_hand_instead_of_discarding_it(): void
    {
        $state = $this->reveal($this->startState());

        $after = $this->apply($state, new ActionRequest(
            actionId: 'late',
            expectedVersion: $state->version,
            type: ActionType::Timeout,
        ))->state;

        $this->assertSame($state->hand, $after->hand);
        $this->assertSame([], $after->discardPile);
    }

    public function test_a_legacy_keep_list_is_ignored_and_never_rejects_a_play(): void
    {
        $state = $this->reveal($this->startState());
        $played = $state->hand[0];

        $after = $this->apply($state, new ActionRequest(
            actionId: 'legacy',
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $played,
            keep: [$played, $state->hand[1], $state->hand[2], $state->hand[3]],
        ))->state;

        $this->assertSame(array_slice($state->hand, 1), $after->hand);
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

        $this->assertCount($after->handSize, $after->hand);
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

        // 抽牌堆還有牌時，補牌不洗牌。
        $state = $this->apply($state, new ActionRequest(
            actionId: 'play-1',
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $state->hand[0],
        ))->state;
        $state = $this->reveal($state);
        $this->assertSame($shuffles, $state->shuffleCount);

        // 把抽牌堆耗盡（牌全進棄牌堆），下一次補牌才從棄牌堆洗回來。
        $state->discardPile = array_merge($state->discardPile, $state->drawPile);
        $state->drawPile = [];
        $state = $this->apply($state, new ActionRequest(
            actionId: 'play-2',
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $state->hand[0],
        ))->state;
        $state = $this->reveal($state);

        $this->assertGreaterThan($shuffles, $state->shuffleCount);
        $this->assertCount($state->handSize, $state->hand);
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
