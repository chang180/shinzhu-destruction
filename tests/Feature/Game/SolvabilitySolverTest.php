<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Outcome;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Solver\SolvabilitySolver;
use App\Domain\Game\Solver\SolverStatus;
use App\Services\Game\DifficultyReport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SolvabilitySolverTest extends TestCase
{
    private function solver(): SolvabilitySolver
    {
        return new SolvabilitySolver(app(BattleEngine::class));
    }

    private function scratchPath(string $name): string
    {
        return sys_get_temp_dir().'/p10-1-'.getmypid().'-'.$name;
    }

    public function test_a_solved_path_replays_step_by_step_to_victory_and_is_reproducible(): void
    {
        $level = app(LevelRepository::class)->get('mirror-shade');
        $modifiers = EquivalenceRecorder::modifiers(-0.15);

        $first = $this->solver()->solve($level, $modifiers, 3, null, 2000);
        $second = $this->solver()->solve($level, $modifiers, 3, null, 2000);

        $this->assertSame(SolverStatus::Solved, $first->status);
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame('reveal', $first->path[0]['type']);
        $this->assertSame(Outcome::PlayerVictory, $this->solver()->replay($level, $modifiers, 3, null, $first->path)->outcome);
    }

    public function test_finds_a_win_on_a_seed_the_planner_loses(): void
    {
        $level = app(LevelRepository::class)->get('stored-night');
        $deck = app(DifficultyReport::class)->deckVariants($level)['smoke-screen+hollow-ground'];
        $modifiers = EquivalenceRecorder::modifiers(-0.15);

        $planner = (new BattleSimulator(app(BattleEngine::class)))->run($level, $modifiers, new PlannerStrategy($modifiers, app(CardCatalog::class)), 6, 'w-h-l-', $deck);
        $solved = $this->solver()->solve($level, $modifiers, 6, $deck, 2000);

        $this->assertFalse($planner->won());
        $this->assertSame(SolverStatus::Solved, $solved->status);
        $this->assertSame(Outcome::PlayerVictory, $this->solver()->replay($level, $modifiers, 6, $deck, $solved->path)->outcome);
    }

    public function test_running_out_of_budget_is_reported_as_unknown_not_as_unsolvable(): void
    {
        $level = app(LevelRepository::class)->get('stored-night');

        $result = $this->solver()->solve($level, EquivalenceRecorder::modifiers(0.0), 1, null, 1);

        $this->assertSame(SolverStatus::ExhaustedUnknown, $result->status);
        $this->assertGreaterThan(0, $result->budgetCuts);
        $this->assertSame([], $result->path);
    }

    public function test_a_hopeless_final_turn_is_proven_unsolvable_only_after_the_full_tree_is_searched(): void
    {
        $level = app(LevelRepository::class)->get('empty-cup');
        $state = app(BattleEngine::class)->start($level, 1);
        $state->turn = $level->maxTurns;

        $result = $this->solver()->solveFrom($state, $level, EquivalenceRecorder::modifiers(0.0), 10000);

        $this->assertSame(SolverStatus::ExhaustivelyUnsolved, $result->status);
        $this->assertSame(0, $result->budgetCuts);
        $this->assertLessThan(10000, $result->nodesExpanded);
    }

    public function test_the_canonical_hash_ignores_the_action_counter_and_flag_order_but_not_the_hand(): void
    {
        $state = app(BattleEngine::class)->start(app(LevelRepository::class)->get('empty-cup'), 1);
        $state->flags = ['a' => true, 'b' => 1];
        $reordered = $state->copy();
        $reordered->flags = ['b' => 1, 'a' => true];
        $reordered->version += 7;
        $otherHand = $state->copy();
        $otherHand->hand = ['long-flow-1'];

        $this->assertSame(SolvabilitySolver::canonicalHash($state), SolvabilitySolver::canonicalHash($reordered));
        $this->assertNotSame(SolvabilitySolver::canonicalHash($state), SolvabilitySolver::canonicalHash($otherHand));
    }

    public function test_the_command_is_reproducible_across_all_five_levels_scenarios_and_reward_decks(): void
    {
        $first = $this->scratchPath('a.json');
        $second = $this->scratchPath('b.json');
        $options = ['--seeds' => 1, '--start-seed' => 3, '--budget' => 2000];

        $this->artisan('game:solve', $options + ['--json' => $first])->assertSuccessful();
        $this->artisan('game:solve', $options + ['--json' => $second])->assertSuccessful();

        $this->assertFileEquals($first, $second);
        $results = json_decode((string) file_get_contents($first), true)['results'];
        // 牌組數 1 + 1 + 2 + 2 + 4，各 3 個情境。
        $this->assertCount(10 * 3, $results);
        $this->assertSame(['empty-cup', 'noon-fold', 'meter-feast', 'mirror-shade', 'stored-night'], array_values(array_unique(array_column($results, 'level'))));
        foreach ($results as $result) {
            $this->assertSame($result['status'] === 'solved', $result['replay_verified'] === true);
        }

        unlink($first);
        unlink($second);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): array
    {
        return [
            'unknown level' => [['--level' => ['nowhere']]],
            'unknown scenario' => [['--scenario' => ['mid']]],
            'unknown deck' => [['--level' => ['empty-cup'], '--deck' => ['tide-siege']]],
            'zero seeds' => [['--seeds' => 0]],
            'non-numeric start seed' => [['--start-seed' => 'abc']],
            'zero budget' => [['--budget' => 0]],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('invalidOptions')]
    public function test_invalid_options_exit_with_an_error_and_write_no_file(array $options): void
    {
        $json = $this->scratchPath('invalid.json');

        $this->artisan('game:solve', $options + ['--seeds' => $options['--seeds'] ?? 1, '--json' => $json])->assertExitCode(2);

        $this->assertFileDoesNotExist($json);
    }
}
