<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Models\Campaign;
use App\Models\Run;
use App\Models\RunAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Random\Randomizer;
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

    /**
     * 用規劃策略把這一局打到玩家勝利，回傳最後一次回應。
     */
    private function winCurrentRun(string $runId): TestResponse
    {
        $engine = app(BattleEngine::class);
        $level = app(LevelRepository::class)->get('empty-cup');
        $run = Run::query()->where('public_id', $runId)->firstOrFail();
        $strategy = new PlannerStrategy(
            ScenarioModifiers::fromArray($run->scenario_modifiers),
        );

        $response = null;

        for ($i = 1; $i <= $level->maxTurns; $i++) {
            $state = Run::query()->where('public_id', $runId)->firstOrFail()->battleState();

            if ($state->outcome->isFinished()) {
                break;
            }

            $choice = $strategy->choose($state, $engine, $level, new Randomizer);

            $response = $this->submit($runId, [
                'action_id' => 'win-'.$i,
                'expected_version' => $state->version,
                'skill_id' => $choice['skill_id'],
                'target' => $choice['target'],
            ]);

            $response->assertOk();
        }

        $this->assertSame('player_victory', Run::query()->where('public_id', $runId)->firstOrFail()->outcome->value);

        return $response;
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

    public function test_an_unknown_level_is_rejected_with_a_traditional_chinese_message(): void
    {
        $this->startRun('no-such-level')
            ->assertStatus(422)
            ->assertJsonValidationErrors('level_id')
            ->assertJsonPath('errors.level_id.0', '這一關不存在');
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
            ->assertJsonValidationErrors('skill_id')
            ->assertJsonPath('errors.skill_id.0', '這個技能不存在');

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
        // 拒絕闖入者不需要先替他建立戰役：資料庫裡仍然只有原本那一位玩家。
        $this->assertSame(1, Campaign::query()->count());
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

    public function test_the_action_endpoint_is_rate_limited_and_says_when_to_retry(): void
    {
        $runId = $this->startRun()->json('data.run_id');
        $limit = 60;
        $response = null;

        // 用同一個 action_id 重送：每次都走完中介層，但只會結算一次。
        for ($i = 0; $i <= $limit; $i++) {
            $response = $this->submit($runId);

            if ($response->getStatusCode() === 429) {
                break;
            }
        }

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));

        // 限流擋掉的請求不會多結算，仍然只有第一次那一筆。
        $this->assertSame(1, RunAction::query()->count());
    }

    public function test_foreign_keys_and_a_busy_timeout_are_enabled_on_sqlite(): void
    {
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertGreaterThan(0, (int) config('database.connections.sqlite.busy_timeout'));
    }

    public function test_read_only_endpoints_do_not_create_campaign_rows(): void
    {
        // 公開的關卡列表不該讓每個沒有 cookie 的請求都在資料庫留下一列。
        for ($i = 0; $i < 5; $i++) {
            $this->flushSession();
            $this->getJson(route('api.v1.levels.index'))->assertOk();
        }

        $this->getJson(route('api.v1.runs.show', ['run' => 'nope']))->assertNotFound();
        $this->getJson(route('api.v1.data-status'))->assertOk();

        $this->assertSame(0, Campaign::query()->count());
    }

    public function test_a_visitor_without_a_campaign_sees_the_default_unlock_state(): void
    {
        $response = $this->getJson(route('api.v1.levels.index'));

        $response->assertOk()
            ->assertJsonPath('levels.0.unlocked', true)
            ->assertJsonPath('levels.0.best', null)
            ->assertJsonPath('levels.1.unlocked', false);
    }

    public function test_starting_a_run_is_what_creates_the_campaign(): void
    {
        $this->assertSame(0, Campaign::query()->count());

        $this->startRun()->assertCreated();

        $this->assertSame(1, Campaign::query()->count());
    }

    public function test_clearing_a_level_records_the_best_result_and_unlocks_the_next_one(): void
    {
        $runId = $this->startRun()->json('data.run_id');
        $this->winCurrentRun($runId);

        $campaign = Campaign::query()->firstOrFail();

        $this->assertArrayHasKey('empty-cup', $campaign->best_results);
        $this->assertSame($runId, $campaign->best_results['empty-cup']['run_id']);
        $this->assertGreaterThan(0, $campaign->best_results['empty-cup']['turns']);
        $this->assertContains('meter-feast', $campaign->unlocked);

        // 解鎖之後才開得起下一關。
        $this->startRun('meter-feast')->assertCreated();

        $this->getJson(route('api.v1.levels.index'))
            ->assertJsonPath('levels.1.unlocked', true)
            ->assertJsonPath('levels.0.best.run_id', $runId);
    }

    public function test_a_won_run_can_be_replayed_with_its_frozen_snapshots(): void
    {
        $runId = $this->startRun()->json('data.run_id');
        $this->winCurrentRun($runId);

        $replay = $this->getJson(route('api.v1.runs.replay', ['run' => $runId]));

        $replay->assertOk()->assertJsonPath('outcome', 'player_victory');

        $this->assertCount(3, $replay->json('snapshots'));
        $this->assertNotEmpty($replay->json('actions'));

        // 最後一則事件必須是結局，而且事件都掛在該行動所屬的回合底下。
        $last = collect($replay->json('actions'))->last();
        $this->assertSame('outcome', collect($last['events'])->last()['type']);
        $this->assertCount(1, collect($last['events'])->pluck('turn')->unique());
    }

    public function test_every_event_from_one_settlement_shares_the_action_turn(): void
    {
        $runId = $this->startRun()->json('data.run_id');

        $events = $this->submit($runId)->json('data.events');
        $turns = array_unique(array_column($events, 'turn'));

        // 包含回合推進在內。依 turn 分組的重播不能把推進歸到下一回合。
        $this->assertSame([1], array_values($turns));
        $this->assertContains('turn_advanced', array_column($events, 'type'));
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
