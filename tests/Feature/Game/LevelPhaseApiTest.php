<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Models\Run;
use App\Models\RunAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Random\Randomizer;
use Tests\TestCase;

/**
 * P10-1 對外契約：關卡幕次的名稱、目標與順序可以從 API 取得，和第 5 關重整的
 * 機制狀態（state.phase）分開；P10-1 之前存下的局缺少幕次欄位時仍可讀、可續打、可比較。
 */
class LevelPhaseApiTest extends TestCase
{
    use RefreshDatabase;

    private function startRun(string $levelId): string
    {
        return $this->postJson(route('api.v1.runs.store'), ['level_id' => $levelId])->assertCreated()->json('data.run_id');
    }

    private function runModel(string $runId): Run
    {
        return Run::query()->where('public_id', $runId)->firstOrFail();
    }

    /**
     * 模擬 P10-1 之前的存檔：從局面與每一筆行動紀錄拿掉 level_phase_id。
     */
    private function stripLevelPhase(string $runId): void
    {
        $run = $this->runModel($runId);
        $state = $run->state;
        unset($state['level_phase_id']);
        $run->forceFill(['state' => $state])->save();

        RunAction::query()->where('run_id', $run->id)->get()->each(function (RunAction $action): void {
            $after = $action->state_after;
            unset($after['level_phase_id']);
            $action->forceFill(['state_after' => $after])->save();
        });
    }

    private function playToEnd(string $runId): void
    {
        $level = app(LevelRepository::class)->get($this->runModel($runId)->level_id);
        $strategy = new PlannerStrategy(ScenarioModifiers::fromArray($this->runModel($runId)->scenario_modifiers), app(CardCatalog::class));

        for ($i = 1; $i <= $level->maxTurns * 2; $i++) {
            $state = $this->runModel($runId)->battleState();

            if ($state->outcome->isFinished()) {
                return;
            }

            $body = $state->turnPhase === BattleState::PHASE_AWAITING_REVEAL
                ? ['type' => 'reveal']
                : (static function (array $choice): array {
                    return ['type' => 'play', 'card_id' => $choice['card_id'], 'fixed' => $choice['fixed'], 'keep' => $choice['keep'] ?? []];
                })($strategy->choose($state, app(BattleEngine::class), $level, new Randomizer));

            $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), $body + [
                'action_id' => "phase-{$i}",
                'expected_version' => $state->version,
            ])->assertOk();
        }
    }

    public function test_levels_publish_their_phase_names_objectives_and_order(): void
    {
        $response = $this->getJson(route('api.v1.levels.index'))->assertOk();
        $levels = collect($response->json('levels'))->keyBy('level_id');

        // P10-4：第 1～4 關三幕，觸發都只用回合門檻；第 5 關仍是單一等價幕次。
        $acts = [
            'empty-cup' => [['trial-cup', '第一幕・試杯', 1], ['cut-supply', '第二幕・斷補', 3], ['empty-cup-quiz', '第三幕・空杯小考', 5]],
            'noon-fold' => [['single-shield', '第一幕・單盾示範', 1], ['shift-guard', '第二幕・輪班防線', 3], ['crossed-windows', '第三幕・交錯窗口', 5]],
            'mirror-shade' => [['reflection', '第一幕・照影', 1], ['borrowed-shadow', '第二幕・借影', 4], ['counter-exam', '第三幕・反制考核', 7]],
            'meter-feast' => [['ledger-prep', '第一幕・帳前準備', 1], ['demand-pulse', '第二幕・需求脈衝', 4], ['peak-settlement', '第三幕・高峰結算', 7]],
        ];

        foreach ($acts as $levelId => $expected) {
            $phases = $levels[$levelId]['phases'];
            $this->assertSame(array_column($expected, 0), array_column($phases, 'id'), $levelId);
            $this->assertSame(array_column($expected, 1), array_column($phases, 'label'), $levelId);
            $this->assertSame([1, 2, 3], array_column($phases, 'order'), $levelId);
            $this->assertSame(array_map(static fn (array $act): array => ['type' => 'turn_gte', 'value' => $act[2]], $expected), array_column($phases, 'starts_when'), $levelId);
            $this->assertSame(
                [sprintf('第 %d 回合起', $expected[1][2]), sprintf('第 %d 回合起', $expected[2][2]), null],
                array_column($phases, 'next_phase_summary'),
                $levelId
            );
            $this->assertNotContains('', array_column($phases, 'objective'), $levelId);
        }

        foreach (['stored-night'] as $levelId) {
            $this->assertSame([[
                'id' => 'main',
                'order' => 1,
                'label' => '全關',
                'objective' => $levels[$levelId]['lesson'],
                'starts_when' => ['type' => 'turn_gte', 'value' => 1],
                'starts_when_summary' => '第 1 回合起',
                'next_phase_summary' => null,
            ]], $levels[$levelId]['phases'], $levelId);
        }

        foreach ($levels as $levelId => $level) {
            $this->assertCount($level['max_turns'], $level['forecast'], $levelId);
        }
    }

    public function test_a_run_reports_its_level_phase_separately_from_the_overhaul_mechanic_state(): void
    {
        $runId = $this->startRun('empty-cup');
        $this->runModel($runId)->campaign->forceFill(['unlocked' => ['empty-cup', 'noon-fold', 'meter-feast', 'mirror-shade', 'stored-night']])->save();
        $storedNight = $this->startRun('stored-night');

        $this->getJson(route('api.v1.runs.show', ['run' => $storedNight]))
            ->assertOk()
            ->assertJsonPath('data.state.phase', 'standby')
            ->assertJsonPath('data.state.level_phase_id', 'main')
            ->assertJsonPath('data.level_phase.id', 'main')
            ->assertJsonPath('data.level_phase.order', 1)
            ->assertJsonPath('data.level_phase.total', 1)
            ->assertJsonPath('data.level_phase.objective', app(LevelRepository::class)->get('stored-night')->lesson)
            ->assertJsonPath('data.level_phase.next_phase_summary', null);

        $this->getJson(route('api.v1.runs.show', ['run' => $runId]))
            ->assertJsonPath('data.state.phase', 'standard')
            ->assertJsonPath('data.level_phase.id', 'trial-cup')
            ->assertJsonPath('data.level_phase.total', 3)
            ->assertJsonPath('data.level_phase.next_phase_summary', '第 3 回合起');
    }

    public function test_a_run_saved_before_level_phases_is_readable_and_can_be_continued(): void
    {
        $runId = $this->startRun('empty-cup');
        $this->playToEnd($runId);
        $finished = $this->runModel($runId)->state;
        $runId = $this->startRun('empty-cup');
        $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), ['action_id' => 'r1', 'expected_version' => 1, 'type' => 'reveal'])->assertOk();
        $this->stripLevelPhase($runId);

        $this->getJson(route('api.v1.runs.show', ['run' => $runId]))
            ->assertOk()
            ->assertJsonPath('data.compatible', true)
            ->assertJsonPath('data.state.level_phase_id', null)
            ->assertJsonPath('data.level_phase.id', 'trial-cup');
        $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), [
            'action_id' => 'r2', 'expected_version' => 2, 'type' => 'play', 'fixed' => 'gather',
        ])->assertOk()->assertJsonPath('data.state.turn', 2);

        $this->assertArrayHasKey('level_phase_id', $finished);
    }

    public function test_replay_and_counterfactual_of_a_pre_phase_save_match_the_same_run_with_the_field(): void
    {
        $runId = $this->startRun('empty-cup');
        $this->playToEnd($runId);
        $sequence = RunAction::query()->where('run_id', $this->runModel($runId)->id)->get()
            ->first(static fn (RunAction $action): bool => $action->input['type'] === 'play')->sequence;
        $body = ['sequence' => $sequence, 'type' => 'play', 'fixed' => 'gather'];

        $withField = $this->postJson(route('api.v1.runs.counterfactual', ['run' => $runId]), $body)->assertOk()->json();
        $replayWithField = $this->getJson(route('api.v1.runs.replay', ['run' => $runId]))->assertOk()->json();
        $this->stripLevelPhase($runId);
        $withoutField = $this->postJson(route('api.v1.runs.counterfactual', ['run' => $runId]), $body)->assertOk()->json();
        $replayWithoutField = $this->getJson(route('api.v1.runs.replay', ['run' => $runId]))->assertOk()->json();

        $this->assertSame($withField, $withoutField);
        // 重播原樣回傳存檔；拿掉幕次欄位之外，其餘內容（行動、事件、最終局面）完全相同。
        unset($replayWithField['final_state']['level_phase_id']);
        $this->assertSame($replayWithField, $replayWithoutField);
    }
}
