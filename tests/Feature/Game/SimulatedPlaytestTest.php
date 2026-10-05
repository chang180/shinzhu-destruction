<?php

namespace Tests\Feature\Game;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P10-7 的試玩替代量測（game:playtest）。
 *
 * 守三件事：同 seed 完全可重現、卡關的玩家不會繼續往後打、輸出一定帶著
 * 「這不是真人證據」的限制說明。
 */
class SimulatedPlaytestTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && File::exists($this->path)) {
            File::delete($this->path);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function runPlaytest(int $players = 2, int $attempts = 2, int $seed = 1): array
    {
        $this->path = storage_path('framework/testing/playtest-'.$seed.'-'.$players.'-'.$attempts.'.json');

        $this->artisan('game:playtest', [
            '--players' => $players,
            '--attempts' => $attempts,
            '--seed' => $seed,
            '--json' => $this->path,
        ])->assertSuccessful();

        return json_decode((string) File::get($this->path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_same_seed_reproduces_the_same_cohort(): void
    {
        $first = $this->runPlaytest();
        $second = $this->runPlaytest();

        $this->assertSame($first['players'], $second['players'], '同一個 seed 必須得到完全一樣的世代');
    }

    public function test_a_different_seed_changes_the_cohort(): void
    {
        $first = $this->runPlaytest(seed: 1);
        $second = $this->runPlaytest(seed: 7);

        $this->assertNotSame($first['players'], $second['players']);
    }

    public function test_a_player_who_cannot_clear_a_level_stops_there(): void
    {
        $report = $this->runPlaytest(players: 5, attempts: 1);

        foreach ($report['players'] as $player) {
            $levels = $player['levels'];
            $failures = array_values(array_filter($levels, static fn (array $row): bool => $row['cleared'] === false));

            if ($failures === []) {
                $this->assertNull($player['stuck_at']);

                continue;
            }

            // 卡關的那一關一定是最後一關：沒過就不往後打。
            $this->assertFalse($levels[count($levels) - 1]['cleared']);
            $this->assertCount(1, $failures, '一位玩家最多只會卡在一關');
            $this->assertSame($levels[count($levels) - 1]['level_id'], $player['stuck_at']);
        }
    }

    public function test_attempts_stop_as_soon_as_the_level_is_cleared(): void
    {
        foreach ($this->runPlaytest(players: 5, attempts: 4)['players'] as $player) {
            foreach ($player['levels'] as $level) {
                $attempts = $level['attempts'];
                $this->assertSame($level['attempts_used'], count($attempts));

                // 只有最後一次嘗試可能是勝利；贏了就不再重試。
                foreach (array_slice($attempts, 0, -1) as $attempt) {
                    $this->assertFalse($attempt['won'], '通關前的每一次嘗試都必須是失敗');
                }

                $last = $attempts[count($attempts) - 1];
                $this->assertSame($level['cleared'], $last['won']);
                $this->assertSame($level['cleared'] ? $last['turns'] : null, $level['clearing_turn']);
            }
        }
    }

    public function test_only_losses_are_counted_as_getting_stuck_in_an_act(): void
    {
        foreach ($this->runPlaytest(players: 5, attempts: 4)['players'] as $player) {
            foreach ($player['levels'] as $level) {
                $losses = count(array_filter($level['attempts'], static fn (array $a): bool => $a['won'] === false));
                $this->assertSame($losses, array_sum($level['failed_in_acts']), '失敗結束的幕次計數要等於失敗次數');
            }
        }
    }

    public function test_the_cohort_covers_every_novice_profile_and_never_uses_the_optimal_planner(): void
    {
        $report = $this->runPlaytest(players: 5, attempts: 1);
        $profiles = array_column($report['players'], 'profile');

        $this->assertSame(array_keys($report['meta']['profiles']), $profiles, '五位玩家要涵蓋全部側寫');
        // planner 是完整前瞻的最佳解，拿它當新手會低估難度。
        $this->assertNotContains('planner', $profiles);
        $this->assertNotContains('planner-one-mistake', $profiles);
    }

    public function test_the_output_states_that_it_is_not_human_evidence(): void
    {
        $meta = $this->runPlaytest()['meta'];

        $this->assertSame(config('game.rules_version'), $meta['rules_version']);
        $this->assertStringContainsString('不是真人', $meta['not_human_evidence']);
        $this->assertStringContainsString('主觀', $meta['not_human_evidence']);
    }
}
