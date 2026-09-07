<?php

namespace Tests\Feature\Game;

use App\Models\Campaign;
use App\Models\Run;
use App\Models\RunAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RunApiTest extends TestCase
{
    use RefreshDatabase;

    private function startRun(string $levelId = 'empty-cup'): TestResponse
    {
        return $this->postJson(route('api.v1.runs.store'), ['level_id' => $levelId]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submit(string $runId, array $overrides = []): TestResponse
    {
        return $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), array_replace([
            'action_id' => 'act-1',
            'expected_version' => 1,
            'skill_id' => 'probe.water',
            'target' => 'water',
        ], $overrides));
    }

    public function test_the_level_list_reports_unlock_state_and_data_status(): void
    {
        $response = $this->getJson(route('api.v1.levels.index'));

        $response->assertOk()
            ->assertJsonPath('rules_version', config('game.rules_version'))
            ->assertJsonPath('levels.0.level_id', 'empty-cup')
            ->assertJsonPath('levels.0.unlocked', true)
            ->assertJsonPath('levels.1.unlocked', false);

        $this->assertSame('unavailable', $response->json('data_status')['wra.reservoir_conditions']['quality']);
    }

    public function test_starting_a_run_freezes_a_snapshot_set_and_returns_the_opening_state(): void
    {
        $response = $this->startRun();

        $response->assertCreated()
            ->assertJsonPath('data.level_id', 'empty-cup')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.outcome', 'in_progress')
            ->assertJsonPath('data.state.core_resilience', 100);

        $run = Run::query()->firstOrFail();

        // 開局綁定三個來源的快照（此處沒有實際快照，因此是示範情境 fixture）。
        $this->assertCount(3, $run->snapshot_ids);
        $this->assertSame(config('game.rules_version'), $run->rules_version);
        $this->assertNotNull($response->json('data.state.intent.description'));
    }

    public function test_a_locked_level_cannot_be_started(): void
    {
        $this->startRun('stored-night')
            ->assertForbidden()
            ->assertJsonPath('reason_code', 'level_locked');
    }

    public function test_a_successful_action_returns_an_ordered_event_stream(): void
    {
        $runId = $this->startRun()->json('data.run_id');

        $response = $this->submit($runId);

        $response->assertOk()
            ->assertJsonPath('data.action_id', 'act-1')
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.events.0.type', 'action_accepted');

        $events = $response->json('data.events');
        $this->assertSame(range(1, count($events)), array_column($events, 'sequence'));

        foreach ($events as $event) {
            $this->assertArrayHasKey('reason_code', $event);
            $this->assertArrayHasKey('cue_id', $event);
        }
    }

    public function test_resending_the_same_action_id_with_the_same_payload_replays_the_original_result(): void
    {
        $runId = $this->startRun()->json('data.run_id');

        $first = $this->submit($runId);
        $second = $this->submit($runId);

        $second->assertOk()->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.events'), $second->json('data.events'));
        $this->assertSame($first->json('data.state'), $second->json('data.state'));

        // 重送沒有多結算一次：只有一筆行動紀錄，資源也沒有多扣。
        $this->assertSame(1, RunAction::query()->count());
        $this->assertSame($first->json('data.state.malice'), Run::query()->firstOrFail()->state['malice']);
    }

    public function test_reusing_an_action_id_with_a_different_payload_is_a_conflict(): void
    {
        $runId = $this->startRun()->json('data.run_id');

        $this->submit($runId);

        $this->submit($runId, ['skill_id' => 'probe.heat', 'target' => 'heat'])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'action_id_reused');

        $this->assertSame(1, RunAction::query()->count());
    }

    public function test_a_stale_expected_version_is_a_conflict_and_settles_nothing(): void
    {
        $runId = $this->startRun()->json('data.run_id');

        $this->submit($runId);

        $response = $this->submit($runId, ['action_id' => 'act-2', 'expected_version' => 1]);

        $response->assertStatus(409)
            ->assertJsonPath('reason_code', 'stale_version')
            ->assertJsonPath('current_version', 2);

        $this->assertSame(1, RunAction::query()->count());
    }

    public function test_an_illegal_action_is_422_and_consumes_no_resources(): void
    {
        $runId = $this->startRun()->json('data.run_id');
        $before = Run::query()->firstOrFail();

        $response = $this->submit($runId, ['skill_id' => 'ultimate', 'target' => null]);

        $response->assertStatus(422)
            ->assertJsonPath('reason_code', 'sigils_not_ready');

        $after = Run::query()->firstOrFail();

        $this->assertSame($before->state, $after->state);
        $this->assertSame($before->version, $after->version);
        $this->assertSame(0, RunAction::query()->count());
    }

    public function test_an_unknown_skill_is_rejected_by_validation(): void
    {
        $runId = $this->startRun()->json('data.run_id');

        $this->submit($runId, ['skill_id' => 'probe.fire', 'target' => 'water'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('skill_id');

        $this->assertSame(0, RunAction::query()->count());
    }

    public function test_another_visitor_cannot_read_or_drive_someone_elses_run(): void
    {
        $runId = $this->startRun()->json('data.run_id');

        // 換一個工作階段等於換一位匿名玩家。
        $this->flushSession();

        $this->getJson(route('api.v1.runs.show', ['run' => $runId]))->assertNotFound();
        $this->submit($runId, ['action_id' => 'intruder'])->assertNotFound();

        $this->assertSame(0, RunAction::query()->count());
        $this->assertSame(2, Campaign::query()->count());
    }

    public function test_the_replay_endpoint_waits_until_the_run_is_finished(): void
    {
        $runId = $this->startRun()->json('data.run_id');

        $this->getJson(route('api.v1.runs.replay', ['run' => $runId]))
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'run_in_progress');
    }

    public function test_a_finished_run_exposes_its_frozen_snapshots_and_full_action_log(): void
    {
        $runId = $this->startRun()->json('data.run_id');
        $version = 1;

        // 一路蓄勢直到回合耗盡，城市守住。
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->submit($runId, [
                'action_id' => 'gather-'.$i,
                'expected_version' => $version,
                'skill_id' => 'gather',
                'target' => null,
            ]);

            $response->assertOk();
            $version = $response->json('data.version');

            if ($response->json('data.state.outcome') !== 'in_progress') {
                break;
            }
        }

        $replay = $this->getJson(route('api.v1.runs.replay', ['run' => $runId]));

        $replay->assertOk()
            ->assertJsonPath('outcome', 'city_held')
            ->assertJsonPath('rules_version', config('game.rules_version'));

        $this->assertNotEmpty($replay->json('actions'));
        $this->assertCount(3, $replay->json('snapshots'));
        $this->assertSame(
            range(1, count($replay->json('actions'))),
            array_column($replay->json('actions'), 'sequence'),
        );
    }

    public function test_the_data_status_endpoint_does_not_leak_internal_detail(): void
    {
        $response = $this->getJson(route('api.v1.data-status'));

        $response->assertOk();

        $body = $response->json();
        $this->assertArrayHasKey('sources', $body);
        $this->assertStringNotContainsString('opendata.wra.gov.tw', json_encode($body));
    }
}
