<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Models\Campaign;
use App\Models\Run;
use App\Services\Game\CampaignProgress;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Random\Randomizer;
use Tests\TestCase;

/**
 * P05 的戰役進度：牌組獎勵、主線收手與進階終幕。
 *
 * 獎勵是替換不是增牌，而且只有限時挑戰通關才算數；收手是結局不是失敗，收手之後
 * 進階仍然打得到（P04-REVISION-PLAN §2、§4.6）。
 */
class CampaignProgressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 這裡一個測試要連打五關，遠超過正常玩家的節奏，會撞到每分鐘 60 次的施招限流。
     * 限流本身由 RunApiTest 驗證，這個檔案量的是戰役進度，所以只在這裡關掉。
     */
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::for('game-actions', fn (): Limit => Limit::none());
    }

    private function state(string $runId): BattleState
    {
        return Run::query()->where('public_id', $runId)->firstOrFail()->battleState();
    }

    private function startRun(string $levelId, string $mode = 'challenge'): TestResponse
    {
        return $this->postJson(route('api.v1.runs.store'), ['level_id' => $levelId, 'mode' => $mode]);
    }

    /**
     * 用規劃策略把指定關卡打到玩家勝利，回傳那一局的 run_id。
     *
     * 策略只讀公開局面，和真人一樣看不到牌序。seed 由伺服器決定，而規劃策略在
     * 最難的一關也不是 100% 勝率，所以允許同一關重開幾局——這正是玩家失敗後
     * 再開一局的流程，不是把勝率灌成必勝。
     */
    private function clear(string $levelId, string $mode = 'challenge'): string
    {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $runId = $this->playOut($levelId, $mode, $attempt);

            if (Run::query()->where('public_id', $runId)->firstOrFail()->outcome->value === 'player_victory') {
                return $runId;
            }
        }

        $this->fail("規劃策略連開四局都沒能通關 {$levelId}");
    }

    private function playOut(string $levelId, string $mode, int $attempt): string
    {
        $runId = $this->startRun($levelId, $mode)->assertCreated()->json('data.run_id');
        $engine = app(BattleEngine::class);
        $level = app(LevelRepository::class)->get($levelId);
        $run = Run::query()->where('public_id', $runId)->firstOrFail();
        $strategy = new PlannerStrategy(ScenarioModifiers::fromArray($run->scenario_modifiers), app(CardCatalog::class));

        for ($i = 1; $i <= $level->maxTurns * 3; $i++) {
            $state = $this->state($runId);

            if ($state->outcome->isFinished()) {
                break;
            }

            $payload = ['action_id' => "try{$attempt}-step-{$i}", 'expected_version' => $state->version];

            if ($state->turnPhase === BattleState::PHASE_AWAITING_REVEAL) {
                $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), $payload + ['type' => 'reveal'])->assertOk();

                continue;
            }

            $choice = $strategy->choose($state, $engine, $level, new Randomizer);

            $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), $payload + [
                'type' => 'play',
                'card_id' => $choice['card_id'],
                'fixed' => $choice['fixed'],
                'keep' => $choice['keep'] ?? [],
            ])->assertOk();
        }

        return $runId;
    }

    public function test_clearing_the_second_level_offers_a_deck_reward_that_swaps_one_card(): void
    {
        $this->clear('empty-cup');
        $this->clear('noon-fold');

        $offer = $this->getJson(route('api.v1.campaign.show'))
            ->assertOk()
            ->json('data.pending_reward');

        $this->assertSame('noon-fold', $offer['level_id']);
        $this->assertCount(2, $offer['options']);
        $this->assertSame(['tide-siege', 'smoke-screen'], array_column($offer['options'], 'key'));

        $this->postJson(route('api.v1.campaign.reward'), ['level_id' => 'noon-fold', 'option' => 'tide-siege'])
            ->assertOk()
            ->assertJsonPath('data.pending_reward', null)
            ->assertJsonPath('data.deck_choices.noon-fold', 'tide-siege');

        $deck = $this->getJson(route('api.v1.campaign.show'))->json('data.deck');

        // 換一張不是多一張：牌組仍然 15 張。
        $this->assertSame(1, $deck['tide-siege']);
        $this->assertSame(2, $deck['long-flow']);
        $this->assertSame(15, array_sum($deck));
    }

    public function test_the_reward_card_actually_appears_in_the_next_run_and_the_old_run_keeps_its_deck(): void
    {
        $this->clear('empty-cup');
        $earlier = $this->clear('noon-fold');

        $this->postJson(route('api.v1.campaign.reward'), ['level_id' => 'noon-fold', 'option' => 'smoke-screen'])->assertOk();

        $next = Run::query()->where('public_id', $this->startRun('meter-feast')->json('data.run_id'))->firstOrFail();

        $this->assertSame(1, $next->deck['smoke-screen']);
        $this->assertContains('smoke-screen', array_values($next->state['deck']));

        // 已經開始的那一局在開局時就凍結了牌組，事後挑的牌不會追溯進去。
        $before = Run::query()->where('public_id', $earlier)->firstOrFail();
        $this->assertArrayNotHasKey('smoke-screen', $before->deck);
        $this->assertNotContains('smoke-screen', array_values($before->state['deck']));
    }

    public function test_retrying_the_same_scenario_keeps_the_frozen_deck(): void
    {
        $this->clear('empty-cup');
        $runId = $this->clear('noon-fold');
        $this->postJson(route('api.v1.campaign.reward'), ['level_id' => 'noon-fold', 'option' => 'tide-siege'])->assertOk();

        $retryId = $this->postJson(route('api.v1.runs.retry', ['run' => $runId]))->assertCreated()->json('data.run_id');

        $original = Run::query()->where('public_id', $runId)->firstOrFail();
        $retry = Run::query()->where('public_id', $retryId)->firstOrFail();

        $this->assertSame($original->deck, $retry->deck);
        $this->assertSame($original->state['deck'], $retry->state['deck']);
    }

    public function test_a_reward_cannot_be_taken_twice_or_without_clearing_the_level(): void
    {
        $this->postJson(route('api.v1.campaign.reward'), ['level_id' => 'noon-fold', 'option' => 'tide-siege'])
            ->assertForbidden()
            ->assertJsonPath('reason_code', 'reward_not_earned');

        $this->clear('empty-cup');
        $this->clear('noon-fold');

        $this->postJson(route('api.v1.campaign.reward'), ['level_id' => 'noon-fold', 'option' => 'tide-siege'])->assertOk();

        $this->postJson(route('api.v1.campaign.reward'), ['level_id' => 'noon-fold', 'option' => 'smoke-screen'])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'reward_already_taken');

        $this->postJson(route('api.v1.campaign.reward'), ['level_id' => 'empty-cup', 'option' => 'tide-siege'])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'no_reward');
    }

    public function test_a_practice_clear_does_not_earn_the_reward_or_the_milestone(): void
    {
        $this->clear('empty-cup', 'practice');
        $this->clear('noon-fold', 'practice');
        $this->clear('meter-feast', 'practice');

        $campaign = Campaign::query()->firstOrFail();

        $this->assertNull($campaign->pending_reward);
        $this->assertSame([], $campaign->milestones());
        $this->assertSame([], $campaign->best_results);

        // 練習軌自己有解鎖紀錄，但不會讓限時挑戰跟著解鎖。
        $this->assertContains('noon-fold', $campaign->practiceUnlocked());
        $this->assertNotContains('noon-fold', $campaign->unlocked);
    }

    public function test_clearing_the_third_level_completes_the_main_campaign_and_standing_down_is_an_ending(): void
    {
        $this->clear('empty-cup');
        $this->clear('noon-fold');
        $this->clear('meter-feast');

        $this->getJson(route('api.v1.campaign.show'))
            ->assertJsonPath('data.main_cleared', true)
            ->assertJsonPath('data.stood_down', false)
            ->assertJsonPath('data.titles.main_cleared', '毀滅計畫通過');

        $this->postJson(route('api.v1.campaign.stand-down'))
            ->assertOk()
            ->assertJsonPath('data.stood_down', true)
            ->assertJsonPath('data.main_cleared', true);

        // 收手不是結束遊戲：進階關卡仍然解鎖著，日後可以從同一份存檔挑戰。
        $campaign = Campaign::query()->firstOrFail();
        $this->assertContains('mirror-shade', $campaign->unlocked);
        $this->startRun('mirror-shade')->assertCreated();
    }

    public function test_standing_down_before_the_main_campaign_is_refused(): void
    {
        $this->clear('empty-cup');

        $this->postJson(route('api.v1.campaign.stand-down'))
            ->assertForbidden()
            ->assertJsonPath('reason_code', 'main_not_cleared');
    }

    public function test_clearing_the_fifth_level_grants_the_advanced_finale_without_touching_the_main_clear(): void
    {
        $this->clear('empty-cup');
        $this->clear('noon-fold');
        $this->clear('meter-feast');
        $this->clear('mirror-shade');
        $this->clear('stored-night');

        $campaign = Campaign::query()->firstOrFail();

        $this->assertTrue($campaign->hasMilestone(CampaignProgress::MAIN_CLEARED));
        $this->assertTrue($campaign->hasMilestone(CampaignProgress::ADVANCED_CLEARED));

        $this->getJson(route('api.v1.campaign.show'))
            ->assertJsonPath('data.advanced_cleared', true)
            ->assertJsonPath('data.titles.advanced_cleared', '首席反派');
    }

    public function test_losing_an_advanced_level_does_not_revoke_the_main_clear(): void
    {
        $this->clear('empty-cup');
        $this->clear('noon-fold');
        $this->clear('meter-feast');

        $runId = $this->startRun('mirror-shade')->json('data.run_id');

        // 一路逾時到回合用盡：這一局輸掉，但主線通關與解鎖都不該被動到。
        for ($i = 1; $i <= 60; $i++) {
            $state = $this->state($runId);

            if ($state->outcome->isFinished()) {
                break;
            }

            $payload = ['action_id' => 'lose-'.$i, 'expected_version' => $state->version];

            $this->postJson(route('api.v1.runs.actions.store', ['run' => $runId]), $payload + [
                'type' => $state->turnPhase === BattleState::PHASE_AWAITING_REVEAL ? 'reveal' : 'play',
                'card_id' => $state->turnPhase === BattleState::PHASE_AWAITING_REVEAL ? null : null,
                'fixed' => $state->turnPhase === BattleState::PHASE_AWAITING_REVEAL ? null : 'gather',
            ])->assertOk();
        }

        $this->assertSame('city_held', Run::query()->where('public_id', $runId)->firstOrFail()->outcome->value);

        $campaign = Campaign::query()->firstOrFail();
        $this->assertTrue($campaign->hasMilestone(CampaignProgress::MAIN_CLEARED));
        $this->assertArrayHasKey('meter-feast', $campaign->best_results);
        $this->assertContains('mirror-shade', $campaign->unlocked);
    }
}
