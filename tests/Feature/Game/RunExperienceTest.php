<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleState;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Outcome;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RunExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function state(string $id): BattleState
    {
        return Run::query()->where('public_id', $id)->firstOrFail()->battleState();
    }

    /**
     * 揭牌 → 蓄勢，走完一個完整回合。回傳最後一次回應。
     *
     * @return TestResponse
     */
    private function holdOneTurn(string $id, string $tag)
    {
        $this->postJson("/api/v1/runs/{$id}/actions", [
            'action_id' => $tag.'-reveal',
            'expected_version' => $this->state($id)->version,
            'type' => 'reveal',
        ])->assertOk();

        return $this->postJson("/api/v1/runs/{$id}/actions", [
            'action_id' => $tag,
            'expected_version' => $this->state($id)->version,
            'type' => 'play',
            'fixed' => 'gather',
        ]);
    }

    public function test_resume_lists_only_the_current_sessions_runs_without_creating_a_campaign(): void
    {
        $this->getJson('/api/v1/runs')->assertOk()->assertJsonPath('data', []);
        $this->assertDatabaseCount('campaigns', 0);
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->json('data.run_id');
        $this->getJson('/api/v1/runs')->assertJsonPath('data', []);
        $this->holdOneTurn($id, 'started')->assertOk();
        $this->getJson('/api/v1/runs')->assertJsonPath('data.0.run_id', $id);
        $this->withSession(['campaign_anonymous_id' => 'another-player'])->getJson('/api/v1/runs')->assertJsonPath('data', []);
        $this->postJson("/api/v1/runs/{$id}/retry")->assertNotFound();
        $this->assertDatabaseCount('runs', 1);
    }

    public function test_revealing_without_finishing_a_turn_does_not_create_a_resume_record(): void
    {
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->assertCreated()->json('data.run_id');
        $this->postJson("/api/v1/runs/{$id}/actions", [
            'action_id' => 'first-reveal', 'expected_version' => 1, 'type' => 'reveal',
        ])->assertOk();
        $this->getJson('/api/v1/runs')->assertOk()->assertJsonPath('data', []);
        $this->assertDatabaseCount('runs', 1);
        $this->getJson("/api/v1/runs/{$id}")->assertOk()->assertJsonPath('data.state.turn', 1);
    }

    public function test_empty_starts_do_not_displace_real_progress_and_resume_reads_the_saved_state(): void
    {
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup', 'mode' => 'practice'])->assertCreated()->json('data.run_id');
        $saved = $this->holdOneTurn($id, 'progress')->assertOk()->json('data');
        for ($index = 0; $index < 21; $index++) {
            $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->assertCreated();
        }
        $this->getJson('/api/v1/runs')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.run_id', $id)->assertJsonPath('data.0.turn', 2)
            ->assertJsonPath('data.0.mode', 'practice')->assertJsonPath('data.0.compatible', true);
        $this->getJson("/api/v1/runs/{$id}")->assertOk()
            ->assertJsonPath('data.state', $saved['state'])->assertJsonPath('data.version', $saved['version']);
        $this->assertDatabaseCount('runs', 22);
        $this->assertDatabaseCount('run_actions', 2);
    }

    public function test_finished_first_turn_and_incompatible_progress_remain_readable_records(): void
    {
        $finishedId = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->assertCreated()->json('data.run_id');
        $finished = Run::query()->where('public_id', $finishedId)->firstOrFail();
        $finished->forceFill(['outcome' => Outcome::CityHeld, 'state' => array_replace($finished->state, ['outcome' => 'city_held'])])->save();
        $oldId = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->assertCreated()->json('data.run_id');
        $this->holdOneTurn($oldId, 'old-progress')->assertOk();
        Run::query()->where('public_id', $oldId)->update(['rules_version' => '3.0.0']);
        $this->getJson('/api/v1/runs')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.run_id', $oldId)->assertJsonPath('data.0.compatible', false)
            ->assertJsonPath('data.1.run_id', $finishedId)->assertJsonPath('data.1.outcome', 'city_held');
    }

    public function test_trial_records_order_by_last_progress_instead_of_creation(): void
    {
        try {
            Carbon::setTestNow('2026-10-13 12:00:00');
            $first = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup', 'mode' => 'practice'])->assertCreated()->json('data.run_id');
            $this->holdOneTurn($first, 'first')->assertOk();
            Carbon::setTestNow('2026-10-13 12:01:00');
            $second = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup', 'mode' => 'practice'])->assertCreated()->json('data.run_id');
            $this->holdOneTurn($second, 'second')->assertOk();
            Carbon::setTestNow('2026-10-13 12:02:00');
            $this->holdOneTurn($first, 'first-resumed')->assertOk();
            $this->getJson('/api/v1/runs')->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.run_id', $first)->assertJsonPath('data.0.turn', 3)
                ->assertJsonPath('data.1.run_id', $second)->assertJsonPath('data.1.turn', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_same_scenario_retry_preserves_frozen_metadata_and_modifiers_and_resets_the_state(): void
    {
        $original = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->json('data');
        $id = $original['run_id'];
        $this->holdOneTurn($id, 'first')->assertOk();
        $retry = $this->postJson("/api/v1/runs/{$id}/retry")->assertCreated()->json('data');
        $this->assertNotSame($id, $retry['run_id']);
        $this->assertSame($original['snapshots'], $retry['snapshots']);
        $this->assertSame($original['scenario'], $retry['scenario']);
        // 同情境重試連 seed 都保留，所以牌組與牌序也一樣，能直接比較不同選擇。
        $this->assertSame($original['state'], $retry['state']);
        $this->assertSame(['demo'], array_values(array_unique(array_column($retry['snapshots'], 'quality'))));
        $this->assertDatabaseHas('runs', ['public_id' => $id, 'version' => 3]);
        $this->assertDatabaseCount('run_actions', 2);
    }

    public function test_old_rules_allow_reading_and_idempotent_replay_but_reject_new_actions_and_retry(): void
    {
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->json('data.run_id');
        $action = ['action_id' => 'first', 'expected_version' => 1, 'type' => 'reveal'];
        $this->postJson("/api/v1/runs/{$id}/actions", $action)->assertOk();
        $run = Run::query()->where('public_id', $id)->firstOrFail();
        $oldState = $run->state;
        unset($oldState['kept_last_turn']);
        $run->forceFill(['rules_version' => '3.0.0', 'state' => $oldState])->save();
        $this->getJson("/api/v1/runs/{$id}")->assertOk()
            ->assertJsonPath('data.compatible', false)
            ->assertJsonPath('data.available_actions', []);
        $this->postJson("/api/v1/runs/{$id}/actions", $action)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson("/api/v1/runs/{$id}/actions", ['action_id' => 'second', 'expected_version' => 2, 'type' => 'play', 'fixed' => 'gather'])
            ->assertConflict()->assertJsonPath('reason_code', 'rules_version_mismatch');
        $this->postJson("/api/v1/runs/{$id}/retry")->assertConflict();
        $this->assertDatabaseHas('runs', ['public_id' => $id, 'version' => 2]);
        $this->assertDatabaseCount('run_actions', 1);
        $this->assertDatabaseCount('runs', 1);
    }

    public function test_unplayed_cards_stay_in_hand_across_api_turns_and_old_keep_lists_are_ignored(): void
    {
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->json('data.run_id');
        $this->postJson("/api/v1/runs/{$id}/actions", [
            'action_id' => 'reveal-first', 'expected_version' => 1, 'type' => 'reveal',
        ])->assertOk();
        $state = $this->state($id);
        $heldBefore = $state->hand;

        $this->postJson("/api/v1/runs/{$id}/actions", [
            'action_id' => 'gather-first', 'expected_version' => $state->version,
            'type' => 'play', 'fixed' => 'gather', 'keep' => [$heldBefore[1]],
        ])->assertOk();
        $this->postJson("/api/v1/runs/{$id}/actions", [
            'action_id' => 'reveal-second', 'expected_version' => $this->state($id)->version, 'type' => 'reveal',
        ])->assertOk();

        $this->assertSame($heldBefore, $this->state($id)->hand);
        $this->assertArrayNotHasKey('kept_last_turn', $this->getJson("/api/v1/runs/{$id}")->json('data.state'));
    }

    public function test_completed_demo_replay_keeps_source_quality_and_real_event_history(): void
    {
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->json('data.run_id');
        $turns = app(LevelRepository::class)->get('empty-cup')->maxTurns;

        for ($turn = 1; $turn <= $turns; $turn++) {
            $this->holdOneTurn($id, "turn-{$turn}")->assertOk();
        }

        $replay = $this->getJson("/api/v1/runs/{$id}/replay")->assertOk()->assertJsonPath('outcome', 'city_held')->json();
        $this->assertSame(['demo'], array_values(array_unique(array_column($replay['snapshots'], 'quality'))));
        // 每回合兩筆：揭牌與出牌。
        $this->assertCount($turns * 2, $replay['actions']);
        $this->getJson("/api/v1/runs/{$id}")->assertJsonCount($turns * 2, 'data.history');
    }

    public function test_briefing_provides_server_skill_costs_and_full_forecast(): void
    {
        $response = $this->getJson('/api/v1/levels')->assertOk();
        $response->assertJsonPath('levels.0.forecast.2.type', 'repair')
            ->assertJsonPath('levels.0.level_id', 'empty-cup')
            ->assertJsonPath('levels.0.name', '枯潮・寶山空杯')
            ->assertJsonPath('levels.0.tier', 'main')
            ->assertJsonPath('levels.0.deck_size', 15)
            ->assertJsonPath('levels.0.forecast.5.scheduled_turn', 6);
        $this->assertSame(5, $response->json('skills')['breach.water']['malice_cost']);
        // 卡面成本一律從技能表讀，牌型不另外寫一份數值。
        $this->assertSame(5, $response->json('cards')['spend-tide']['malice_cost']);
        $this->assertSame(30, $response->json('decision_seconds'));
    }
}
