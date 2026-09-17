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
use Illuminate\Testing\TestResponse;
use Random\Randomizer;
use Tests\TestCase;

/**
 * P07 反事實比較：換掉一次已結算的決策，讓模擬策略接手打完剩下的回合。
 * 驗收依 P04-REVISION-PLAN §P07：文案能指到實際回合、更換關鍵行動後戰報
 * 跟著變、反事實結果來自模擬（同輸入必得同結果）。
 */
class CounterfactualTest extends TestCase
{
    use RefreshDatabase;

    private function startRun(string $levelId = 'empty-cup'): TestResponse
    {
        return $this->postJson(route('api.v1.runs.store'), ['level_id' => $levelId]);
    }

    private function act(string $runId, array $overrides): TestResponse
    {
        return $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), $overrides);
    }

    private function state(string $runId): BattleState
    {
        return Run::query()->where('public_id', $runId)->firstOrFail()->battleState();
    }

    private function counterfactual(string $runId, array $body): TestResponse
    {
        return $this->postJson(route('api.v1.runs.counterfactual', ['run' => $runId]), $body);
    }

    /**
     * 用規劃策略把這一局打到玩家勝利，回傳 run_id。
     */
    private function winRun(string $levelId = 'empty-cup'): string
    {
        $runId = $this->startRun($levelId)->json('data.run_id');
        $engine = app(BattleEngine::class);
        $level = app(LevelRepository::class)->get($levelId);
        $run = Run::query()->where('public_id', $runId)->firstOrFail();
        $strategy = new PlannerStrategy(
            ScenarioModifiers::fromArray($run->scenario_modifiers),
            app(CardCatalog::class),
        );

        for ($i = 1; $i <= $level->maxTurns * 2; $i++) {
            $state = $this->state($runId);

            if ($state->outcome->isFinished()) {
                break;
            }

            if ($state->turnPhase === BattleState::PHASE_AWAITING_REVEAL) {
                $this->act($runId, [
                    'action_id' => 'win-reveal-'.$i,
                    'expected_version' => $state->version,
                    'type' => 'reveal',
                ])->assertOk();

                continue;
            }

            $choice = $strategy->choose($state, $engine, $level, new Randomizer);

            $this->act($runId, [
                'action_id' => 'win-'.$i,
                'expected_version' => $state->version,
                'type' => 'play',
                'card_id' => $choice['card_id'],
                'fixed' => $choice['fixed'],
                'keep' => $choice['keep'] ?? [],
            ])->assertOk();
        }

        $this->assertSame('player_victory', Run::query()->where('public_id', $runId)->firstOrFail()->outcome->value);

        return $runId;
    }

    private function firstPlaySequence(string $runId): int
    {
        $run = Run::query()->where('public_id', $runId)->firstOrFail();

        return RunAction::query()
            ->where('run_id', $run->id)
            ->get()
            ->first(fn (RunAction $action): bool => $action->input['type'] === 'play')
            ->sequence;
    }

    /**
     * 真正打出致勝一擊的那一筆行動序號。勝利只可能來自出牌造成的傷害，
     * 所以贏面那一局最後一筆 play 一定就是它。
     */
    private function winningBlowSequence(string $runId): int
    {
        $run = Run::query()->where('public_id', $runId)->firstOrFail();

        return RunAction::query()
            ->where('run_id', $run->id)
            ->get()
            ->filter(fn (RunAction $action): bool => $action->input['type'] === 'play')
            ->last()
            ->sequence;
    }

    public function test_replacing_the_first_decision_with_a_gather_still_reports_the_real_outcome_unchanged(): void
    {
        $runId = $this->winRun();
        $sequence = $this->firstPlaySequence($runId);

        $response = $this->counterfactual($runId, [
            'sequence' => $sequence,
            'type' => 'play',
            'fixed' => 'gather',
        ]);

        $response->assertOk()
            ->assertJsonPath('sequence', $sequence)
            ->assertJsonPath('strategy', 'planner')
            ->assertJsonPath('actual.outcome', 'player_victory');

        $this->assertContains($response->json('counterfactual.outcome'), ['player_victory', 'city_held']);
        $this->assertIsInt($response->json('counterfactual.turns'));

        // 反事實只是「如果」，實際那一局的紀錄一個字都不能被改到。
        $run = Run::query()->where('public_id', $runId)->firstOrFail();
        $this->assertSame('player_victory', $run->outcome->value);
    }

    public function test_the_same_alternative_always_produces_the_same_comparison(): void
    {
        $runId = $this->winRun();
        $sequence = $this->firstPlaySequence($runId);
        $body = ['sequence' => $sequence, 'type' => 'play', 'fixed' => 'gather'];

        $first = $this->counterfactual($runId, $body)->assertOk()->json();
        $second = $this->counterfactual($runId, $body)->assertOk()->json();

        $this->assertSame($first, $second);
    }

    public function test_replaying_the_actual_winning_move_reproduces_the_real_result_exactly(): void
    {
        $runId = $this->winRun();
        $run = Run::query()->where('public_id', $runId)->firstOrFail();
        $sequence = $this->winningBlowSequence($runId);
        $actualBlow = RunAction::query()->where('run_id', $run->id)->where('sequence', $sequence)->firstOrFail();

        $response = $this->counterfactual($runId, [
            'sequence' => $sequence,
            'type' => $actualBlow->input['type'],
            'card_id' => $actualBlow->input['card_id'],
            'fixed' => $actualBlow->input['fixed'],
            'keep' => $actualBlow->input['keep'] ?? [],
        ]);

        // 換上去的是「和實際一樣的那一手」：模擬必須重現同一個結果，這才叫反事實
        // 結果來自模擬，不是另外編一套說法。
        $response->assertOk()
            ->assertJsonPath('counterfactual.outcome', 'player_victory')
            ->assertJsonPath('counterfactual.turns', (int) $run->state['turn'])
            ->assertJsonPath('counterfactual.diverged', false);
    }

    public function test_replacing_the_winning_blow_with_gather_prevents_that_win(): void
    {
        $runId = $this->winRun();
        $sequence = $this->winningBlowSequence($runId);
        $actualTurns = (int) Run::query()->where('public_id', $runId)->firstOrFail()->state['turn'];

        $response = $this->counterfactual($runId, ['sequence' => $sequence, 'type' => 'play', 'fixed' => 'gather'])
            ->assertOk();

        // 蓄勢完全不衝擊核心，不可能在同一回合複製出原本的致勝一擊：這一手换掉，
        // 戰報就一定要跟著變——不是提早結束就是要多打幾回合甚至守不下來。
        $this->assertTrue(
            $response->json('counterfactual.diverged') === true || $response->json('counterfactual.turns') > $actualTurns,
            '把致勝一擊換成蓄勢，比較結果不可能和實際一模一樣',
        );
    }

    public function test_reveal_is_not_a_replayable_decision(): void
    {
        $runId = $this->winRun();

        $this->counterfactual($runId, ['sequence' => 1, 'type' => 'play', 'fixed' => 'gather'])
            ->assertStatus(422)
            ->assertJsonPath('reason_code', 'sequence_not_replayable');
    }

    public function test_an_illegal_alternative_action_is_rejected_without_touching_the_run(): void
    {
        $runId = $this->winRun();
        $sequence = $this->firstPlaySequence($runId);

        $this->counterfactual($runId, ['sequence' => $sequence, 'type' => 'play', 'fixed' => 'ultimate'])
            ->assertStatus(422);

        $run = Run::query()->where('public_id', $runId)->firstOrFail();
        $this->assertSame('player_victory', $run->outcome->value);
    }

    public function test_an_unfinished_run_cannot_be_compared_yet(): void
    {
        $runId = $this->startRun()->json('data.run_id');

        $this->counterfactual($runId, ['sequence' => 1, 'type' => 'play', 'fixed' => 'gather'])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'run_in_progress');
    }

    public function test_another_visitor_cannot_run_a_counterfactual_on_someone_elses_run(): void
    {
        $runId = $this->winRun();

        $this->flushSession();

        $this->counterfactual($runId, ['sequence' => 1, 'type' => 'play', 'fixed' => 'gather'])
            ->assertNotFound();
    }
}
