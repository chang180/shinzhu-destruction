<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleState;
use App\Models\Run;
use App\Models\RunAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 30 秒決策窗口、逾時與不限時練習（P04-REVISION-PLAN §5）。
 *
 * 所有時間判定都走伺服器時鐘，因此測試用 Carbon 的測試時間推進，而不是真的等。
 */
class DecisionWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function startRun(string $mode = 'challenge'): string
    {
        return $this->postJson(route('api.v1.runs.store'), [
            'level_id' => 'empty-cup',
            'mode' => $mode,
        ])->assertCreated()->json('data.run_id');
    }

    private function state(string $runId): BattleState
    {
        return Run::query()->where('public_id', $runId)->firstOrFail()->battleState();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $runId, array $payload): TestResponse
    {
        return $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), $payload);
    }

    private function reveal(string $runId, string $actionId = 'reveal-1'): TestResponse
    {
        return $this->send($runId, [
            'action_id' => $actionId,
            'expected_version' => $this->state($runId)->version,
            'type' => 'reveal',
        ]);
    }

    public function test_revealing_sets_a_server_deadline_thirty_seconds_out(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();

        $response = $this->reveal($runId)->assertOk();
        $deadline = Carbon::parse($response->json('data.state.deadline_at'));

        $this->assertSame(30, (int) Carbon::now()->diffInSeconds($deadline));
        // 客戶端要靠伺服器時間對齊自己的時鐘，不能拿本機時間判逾時。
        $this->assertNotNull($response->json('data.server_time'));
    }

    public function test_the_briefing_and_the_gap_between_turns_do_not_burn_decision_time(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();

        // 開局後不計時：先讀簡報三分鐘，揭牌時才開始倒數。
        $this->assertNull($this->state($runId)->deadlineAt);

        Carbon::setTestNow('2026-09-09 12:03:00');
        $this->reveal($runId)->assertOk();

        $this->assertSame(
            Carbon::parse('2026-09-09 12:03:30')->getTimestamp(),
            Carbon::parse($this->state($runId)->deadlineAt)->getTimestamp(),
        );
    }

    public function test_an_action_that_arrives_before_the_deadline_settles_normally(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $this->reveal($runId)->assertOk();

        Carbon::setTestNow('2026-09-09 12:00:29');
        $state = $this->state($runId);

        $response = $this->send($runId, [
            'action_id' => 'play-1',
            'expected_version' => $state->version,
            'type' => 'play',
            'card_id' => $state->hand[0],
        ])->assertOk();

        $this->assertContains('impact', array_column($response->json('data.events'), 'type'));
    }

    public function test_a_play_that_arrives_at_the_deadline_settles_as_a_timeout_instead(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $this->reveal($runId)->assertOk();

        $state = $this->state($runId);
        $maliceBefore = $state->malice;

        // 相等即逾時：截止時間本身屬於「已經來不及」。
        Carbon::setTestNow('2026-09-09 12:00:30');

        $response = $this->send($runId, [
            'action_id' => 'late-play',
            'expected_version' => $state->version,
            'type' => 'play',
            'card_id' => $state->hand[0],
        ])->assertOk();

        $types = array_column($response->json('data.events'), 'type');

        $this->assertContains('action_missed', $types);
        // 遲到的牌沒有被施放，也沒有先逾時再補打。
        $this->assertNotContains('impact', $types);

        $after = $this->state($runId);
        $this->assertSame(1, $after->timeouts);
        $this->assertSame(2, $after->turn);
        // 逾時不扣未出的牌費，正常的下回合惡意回復仍然適用。
        $this->assertGreaterThan($maliceBefore, $after->malice);
    }

    public function test_resending_the_same_late_play_replays_the_one_timeout(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $this->reveal($runId)->assertOk();

        $state = $this->state($runId);
        $payload = [
            'action_id' => 'late-play',
            'expected_version' => $state->version,
            'type' => 'play',
            'card_id' => $state->hand[0],
        ];

        Carbon::setTestNow('2026-09-09 12:00:31');
        $first = $this->send($runId, $payload)->assertOk();
        $second = $this->send($runId, $payload)->assertOk()->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.events'), $second->json('data.events'));
        $this->assertSame(1, $this->state($runId)->timeouts);
        // 揭牌一筆、逾時一筆。重送沒有再結算一次。
        $this->assertSame(2, RunAction::query()->count());
    }

    public function test_an_explicit_timeout_before_the_deadline_is_rejected(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $this->reveal($runId)->assertOk();

        Carbon::setTestNow('2026-09-09 12:00:10');

        $this->send($runId, [
            'action_id' => 'early-timeout',
            'expected_version' => $this->state($runId)->version,
            'type' => 'timeout',
        ])->assertStatus(422)->assertJsonPath('reason_code', 'not_timed_out');

        $this->assertSame(0, $this->state($runId)->timeouts);
    }

    public function test_a_disconnected_player_settles_at_most_one_expired_turn(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $this->reveal($runId)->assertOk();

        // 斷線十分鐘後回來。錯過的只有那一個已揭牌的回合。
        Carbon::setTestNow('2026-09-09 12:10:00');

        $this->send($runId, [
            'action_id' => 'catch-up',
            'expected_version' => $this->state($runId)->version,
            'type' => 'timeout',
        ])->assertOk();

        $after = $this->state($runId);

        $this->assertSame(1, $after->timeouts);
        $this->assertSame(2, $after->turn);
        // 下一回合尚未揭牌，所以不會連續空跑整局。
        $this->assertSame(BattleState::PHASE_AWAITING_REVEAL, $after->turnPhase);

        $this->send($runId, [
            'action_id' => 'catch-up-2',
            'expected_version' => $after->version,
            'type' => 'timeout',
        ])->assertStatus(422)->assertJsonPath('reason_code', 'hand_not_revealed');
    }

    public function test_reading_the_run_never_settles_a_timeout_on_its_own(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $this->reveal($runId)->assertOk();
        $versionBefore = $this->state($runId)->version;

        Carbon::setTestNow('2026-09-09 12:05:00');

        $this->getJson(route('api.v1.runs.show', ['run' => $runId]))->assertOk();
        $this->getJson(route('api.v1.runs.index'))->assertOk();

        $after = $this->state($runId);
        $this->assertSame($versionBefore, $after->version);
        $this->assertSame(0, $after->timeouts);
    }

    public function test_a_missed_action_wastes_the_open_breach_window(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $this->reveal($runId)->assertOk();

        $run = Run::query()->where('public_id', $runId)->firstOrFail();
        $state = $run->battleState();
        $state->breachAvailable = true;
        $run->forceFill(['state' => $state->toArray()])->save();

        Carbon::setTestNow('2026-09-09 12:00:31');

        $response = $this->send($runId, [
            'action_id' => 'missed',
            'expected_version' => $state->version,
            'type' => 'timeout',
        ])->assertOk();

        $this->assertContains('breach_consumed', array_column($response->json('data.events'), 'type'));
        $this->assertFalse($this->state($runId)->breachAvailable);
    }

    public function test_practice_mode_opens_the_same_hand_with_no_deadline(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun('practice');

        $this->reveal($runId)->assertOk()->assertJsonPath('data.state.deadline_at', null);

        // 一小時後出牌仍然照常結算，不會被判成逾時。
        Carbon::setTestNow('2026-09-09 13:00:00');
        $state = $this->state($runId);

        $response = $this->send($runId, [
            'action_id' => 'slow-play',
            'expected_version' => $state->version,
            'type' => 'play',
            'card_id' => $state->hand[0],
        ])->assertOk();

        $this->assertContains('impact', array_column($response->json('data.events'), 'type'));
        $this->assertSame(0, $this->state($runId)->timeouts);
        $this->assertCount(5, $state->hand);
    }

    public function test_a_second_tab_cannot_settle_the_same_turn_twice(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $this->reveal($runId)->assertOk();

        $state = $this->state($runId);

        // 兩個分頁都拿著同一個版本，各自送出自己的行動。
        $this->send($runId, [
            'action_id' => 'tab-a',
            'expected_version' => $state->version,
            'type' => 'play',
            'card_id' => $state->hand[0],
        ])->assertOk();

        $this->send($runId, [
            'action_id' => 'tab-b',
            'expected_version' => $state->version,
            'type' => 'play',
            'card_id' => $state->hand[1],
        ])->assertStatus(409)->assertJsonPath('reason_code', 'stale_version');

        // 揭牌一筆、出牌一筆。第二個分頁沒有讓這一回合結算兩次。
        $this->assertSame(2, RunAction::query()->count());
        $this->assertSame(2, $this->state($runId)->turn);
    }

    public function test_a_second_tab_cannot_reveal_the_same_turn_twice(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $version = $this->state($runId)->version;

        $first = $this->send($runId, ['action_id' => 'tab-a', 'expected_version' => $version, 'type' => 'reveal'])
            ->assertOk();

        // 第二個分頁按下「開始回合」：不能重發一手牌，也不能重設倒數。
        $this->send($runId, ['action_id' => 'tab-b', 'expected_version' => $version, 'type' => 'reveal'])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'stale_version');

        $this->assertSame($first->json('data.state.hand'), $this->state($runId)->hand);
        $this->assertSame($first->json('data.state.deadline_at'), $this->state($runId)->deadlineAt);
    }

    public function test_a_second_tab_cannot_spend_the_free_swap_twice(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $runId = $this->startRun();
        $this->reveal($runId)->assertOk();

        $state = $this->state($runId);

        $this->send($runId, [
            'action_id' => 'swap-a',
            'expected_version' => $state->version,
            'type' => 'swap',
            'card_id' => $state->hand[0],
        ])->assertOk();

        $this->send($runId, [
            'action_id' => 'swap-b',
            'expected_version' => $state->version,
            'type' => 'swap',
            'card_id' => $state->hand[1],
        ])->assertStatus(409)->assertJsonPath('reason_code', 'stale_version');

        $this->assertTrue($this->state($runId)->swapUsed);
    }

    public function test_a_practice_clear_does_not_unlock_the_timed_challenge(): void
    {
        $runId = $this->startRun('practice');
        $run = Run::query()->where('public_id', $runId)->firstOrFail();

        // 直接把這一局判成練習通關，重點是紀錄寫到哪一軌。
        $state = $run->battleState();
        $state->coreResilience = 1;
        $run->forceFill(['state' => $state->toArray()])->save();

        $this->send($runId, ['action_id' => 'r', 'expected_version' => $state->version, 'type' => 'reveal'])->assertOk();
        $current = $this->state($runId);
        $breach = collect($current->hand)->first(
            fn (string $id): bool => str_starts_with($current->deck[$id], 'spend-tide')
                || str_starts_with($current->deck[$id], 'long-flow'),
        );

        $this->send($runId, [
            'action_id' => 'finish',
            'expected_version' => $current->version,
            'type' => 'play',
            'card_id' => $breach,
        ])->assertOk();

        $campaign = $run->fresh()->campaign;

        $this->assertSame('player_victory', $run->fresh()->outcome->value);
        $this->assertArrayHasKey('empty-cup', $campaign->practiceResults());
        // 練習可以解鎖練習關，但不能讓限時挑戰的關卡跟著解鎖。
        $this->assertSame([], $campaign->best_results);
        $this->assertNotContains('noon-fold', $campaign->unlocked);
        $this->assertContains('noon-fold', $campaign->practiceUnlocked());
    }
}
