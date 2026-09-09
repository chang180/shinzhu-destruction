<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleState;
use App\Domain\Game\LevelRepository;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->getJson('/api/v1/runs')->assertJsonPath('data.0.run_id', $id);
        $this->withSession(['campaign_anonymous_id' => 'another-player'])->getJson('/api/v1/runs')->assertJsonPath('data', []);
        $this->postJson("/api/v1/runs/{$id}/retry")->assertNotFound();
        $this->assertDatabaseCount('runs', 1);
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
        Run::query()->where('public_id', $id)->update(['rules_version' => 'old']);
        $this->getJson("/api/v1/runs/{$id}")->assertOk()->assertJsonPath('data.compatible', false)->assertJsonPath('data.available_actions', []);
        $this->postJson("/api/v1/runs/{$id}/actions", $action)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson("/api/v1/runs/{$id}/actions", ['action_id' => 'second', 'expected_version' => 2, 'type' => 'play', 'fixed' => 'gather'])
            ->assertConflict()->assertJsonPath('reason_code', 'rules_version_mismatch');
        $this->postJson("/api/v1/runs/{$id}/retry")->assertConflict();
        $this->assertDatabaseHas('runs', ['public_id' => $id, 'version' => 2]);
        $this->assertDatabaseCount('run_actions', 1);
        $this->assertDatabaseCount('runs', 1);
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
