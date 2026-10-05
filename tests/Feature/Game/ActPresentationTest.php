<?php

namespace Tests\Feature\Game;

use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P10-6 的驗收不變量：進入下一幕是一個純提示，不吃決策時間、不多走回合、
 * 不改寫已經顯示出去的預告。幕次顯示本身走前端（tests/p10-acts-ui.test.cjs），
 * 這裡守的是伺服器端的契約。
 */
class ActPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 打到第一次換幕為止，回傳每一回合的（回合, 幕, 截止時間）。
     *
     * @return list<array{turn: int, phase: ?string, deadline: ?string, server_time: ?string, intent: ?string}>
     */
    private function playUntilActChange(string $runId, int $maxTurns): array
    {
        $log = [];

        for ($i = 1; $i <= $maxTurns; $i++) {
            $revealed = $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), [
                'action_id' => "reveal-{$i}",
                'expected_version' => $this->runRecord($runId)->battleState()->version,
                'type' => 'reveal',
            ])->assertOk();

            $log[] = [
                'turn' => $revealed->json('data.state.turn'),
                'phase' => $revealed->json('data.state.level_phase_id'),
                'deadline' => $revealed->json('data.state.deadline_at'),
                'server_time' => $revealed->json('data.server_time'),
                'intent' => $revealed->json('data.state.intent.description'),
            ];

            $state = $this->runRecord($runId)->battleState();

            if ($state->outcome->isFinished()) {
                break;
            }

            // 固定用蓄勢：不花惡意、不受冷卻影響，所以每一關都走得完整幕次邊界。
            $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), [
                'action_id' => "play-{$i}",
                'expected_version' => $state->version,
                'type' => 'play',
                'card_id' => null,
                'fixed' => 'gather',
                'keep' => [],
            ])->assertOk();

            if ($this->runRecord($runId)->battleState()->outcome->isFinished()) {
                break;
            }
        }

        return $log;
    }

    private function runRecord(string $runId): Run
    {
        return Run::query()->where('public_id', $runId)->firstOrFail();
    }

    public function test_crossing_an_act_boundary_advances_exactly_one_turn_and_keeps_a_full_decision_window(): void
    {
        Carbon::setTestNow('2026-10-06 12:00:00');
        $runId = $this->postJson(route('api.v1.runs.store'), ['level_id' => 'empty-cup', 'mode' => 'challenge'])
            ->assertCreated()->json('data.run_id');

        $log = $this->playUntilActChange($runId, 8);
        $changes = [];

        foreach ($log as $index => $row) {
            if ($index > 0 && $row['phase'] !== $log[$index - 1]['phase']) {
                $changes[] = $index;
            }
        }

        $this->assertNotEmpty($changes, '第 1 關應該至少換幕一次');

        foreach ($log as $row) {
            $this->assertSame(
                (int) config('game.timer.decision_seconds'),
                (int) Carbon::parse($row['server_time'])->diffInSeconds(Carbon::parse($row['deadline'])),
                "第 {$row['turn']} 回合的決策窗口不是完整長度"
            );
        }

        foreach ($changes as $index) {
            $before = $log[$index - 1];
            $after = $log[$index];

            // 換幕不是額外的一步：回合剛好加一。
            $this->assertSame($before['turn'] + 1, $after['turn'], '換幕的那一回合只前進一回合');

            // 換幕之後的揭牌仍然是完整 30 秒：截止時間是從該次揭牌的伺服器時間起算。
            // 演出在揭牌之前播完，所以不管演出多長，決策窗口都不會被吃掉。
            $this->assertSame(
                (int) config('game.timer.decision_seconds'),
                (int) Carbon::parse($after['server_time'])->diffInSeconds(Carbon::parse($after['deadline'])),
                '換幕後的決策窗口沒有被縮短'
            );
        }
    }

    public function test_the_shown_intent_is_never_rewritten_by_the_act_it_belongs_to(): void
    {
        Carbon::setTestNow('2026-10-06 12:00:00');
        $runId = $this->postJson(route('api.v1.runs.store'), ['level_id' => 'empty-cup', 'mode' => 'challenge'])
            ->assertCreated()->json('data.run_id');

        $log = $this->playUntilActChange($runId, 8);

        // 每一回合都必須有預告，而且同一回合的預告在揭牌後就固定下來。
        foreach ($log as $row) {
            $this->assertNotNull($row['intent'], "第 {$row['turn']} 回合應該有城市預告");
        }

        $turns = array_column($log, 'turn');
        $this->assertSame(array_values(array_unique($turns)), $turns, '同一回合不會被揭牌兩次');
    }

    public function test_practice_mode_has_no_deadline_across_an_act_change(): void
    {
        $runId = $this->postJson(route('api.v1.runs.store'), ['level_id' => 'empty-cup', 'mode' => 'practice'])
            ->assertCreated()->json('data.run_id');

        foreach ($this->playUntilActChange($runId, 8) as $row) {
            $this->assertNull($row['deadline'], '練習模式任何一幕都沒有截止時間');
        }
    }
}
