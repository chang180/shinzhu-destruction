<?php

namespace Tests\Feature\Game;

use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_lists_only_the_current_sessions_runs_without_creating_a_campaign(): void
    {
        $this->getJson('/api/v1/runs')->assertOk()->assertJsonPath('data', []);
        $this->assertDatabaseCount('campaigns', 0);
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->json('data.run_id');
        $this->getJson('/api/v1/runs')->assertJsonPath('data.0.run_id', $id);
        $this->withSession(['campaign_anonymous_id' => 'another-player'])->getJson('/api/v1/runs')->assertJsonPath('data', []);
        $this->postJson("/api/v1/runs/{$id}/retry")->assertNotFound();
        $this->assertDatabaseCount('runs', 1);
    }

    public function test_same_scenario_retry_preserves_frozen_metadata_and_modifiers_and_resets_the_state(): void
    {
        $original = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->json('data');
        $id = $original['run_id'];
        $this->postJson("/api/v1/runs/{$id}/actions", ['action_id' => 'first', 'expected_version' => 1, 'skill_id' => 'gather'])->assertOk();
        $retry = $this->postJson("/api/v1/runs/{$id}/retry")->assertCreated()->json('data');
        $this->assertNotSame($id, $retry['run_id']);
        $this->assertSame($original['snapshots'], $retry['snapshots']);
        $this->assertSame($original['scenario'], $retry['scenario']);
        $this->assertSame($original['state'], $retry['state']);
        $this->assertSame(['demo'], array_values(array_unique(array_column($retry['snapshots'], 'quality'))));
        $this->assertDatabaseHas('runs', ['public_id' => $id, 'version' => 2]);
        $this->assertDatabaseCount('run_actions', 1);
    }

    public function test_old_rules_allow_reading_and_idempotent_replay_but_reject_new_actions_and_retry(): void
    {
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->json('data.run_id');
        $action = ['action_id' => 'first', 'expected_version' => 1, 'skill_id' => 'gather'];
        $this->postJson("/api/v1/runs/{$id}/actions", $action)->assertOk();
        Run::query()->where('public_id', $id)->update(['rules_version' => 'old']);
        $this->getJson("/api/v1/runs/{$id}")->assertOk()->assertJsonPath('data.compatible', false)->assertJsonPath('data.available_actions', []);
        $this->postJson("/api/v1/runs/{$id}/actions", $action)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson("/api/v1/runs/{$id}/actions", ['action_id' => 'second', 'expected_version' => 2, 'skill_id' => 'gather'])
            ->assertConflict()->assertJsonPath('reason_code', 'rules_version_mismatch');
        $this->postJson("/api/v1/runs/{$id}/retry")->assertConflict();
        $this->assertDatabaseHas('runs', ['public_id' => $id, 'version' => 2]);
        $this->assertDatabaseCount('run_actions', 1);
        $this->assertDatabaseCount('runs', 1);
    }

    public function test_completed_demo_replay_keeps_source_quality_and_real_event_history(): void
    {
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])->json('data.run_id');
        for ($turn = 1; $turn <= 10; $turn++) {
            $this->postJson("/api/v1/runs/{$id}/actions", ['action_id' => "turn-{$turn}", 'expected_version' => $turn, 'skill_id' => 'gather'])->assertOk();
        }
        $replay = $this->getJson("/api/v1/runs/{$id}/replay")->assertOk()->assertJsonPath('outcome', 'city_held')->json();
        $this->assertSame(['demo'], array_values(array_unique(array_column($replay['snapshots'], 'quality'))));
        $this->assertCount(10, $replay['actions']);
        $this->getJson("/api/v1/runs/{$id}")->assertJsonCount(10, 'data.history');
    }

    public function test_briefing_provides_server_skill_costs_and_full_forecast(): void
    {
        $response = $this->getJson('/api/v1/levels')->assertOk();
        $response->assertJsonPath('levels.0.forecast.2.type', 'repair')
            ->assertJsonPath('levels.0.forecast.5.scheduled_turn', 6);
        $this->assertSame(5, $response->json('skills')['breach.water']['malice_cost']);
    }
}
