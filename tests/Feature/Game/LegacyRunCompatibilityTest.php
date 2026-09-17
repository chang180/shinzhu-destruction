<?php

namespace Tests\Feature\Game;

use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyRunCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_v1_save_remains_readable_without_rewriting_its_state(): void
    {
        $run = $this->legacyRun();
        $stored = $run->state;

        $this->getJson("/api/v1/runs/{$run->public_id}")
            ->assertOk()
            ->assertJsonPath('data.compatible', false)
            ->assertJsonPath('data.available_actions', [])
            ->assertJsonPath('data.state.turn', 2)
            ->assertJsonPath('data.state.core_resilience', 88)
            ->assertJsonPath('data.state.hand', [])
            ->assertJsonPath('data.state.draw_pile_count', 0)
            ->assertJsonMissingPath('data.state.draw_pile')
            ->assertJsonMissingPath('data.state.deck_seed');

        $this->assertSame($stored, $run->fresh()->state);
        $this->assertDatabaseCount('run_actions', 0);
    }

    public function test_a_v1_save_cannot_be_resumed_or_retried_under_current_rules(): void
    {
        $run = $this->legacyRun();
        $stored = $run->state;

        $this->postJson("/api/v1/runs/{$run->public_id}/actions", [
            'action_id' => 'new-action',
            'expected_version' => 2,
            'type' => 'reveal',
        ])->assertConflict()->assertJsonPath('reason_code', 'rules_version_mismatch');
        $this->postJson("/api/v1/runs/{$run->public_id}/retry")
            ->assertConflict()->assertJsonPath('reason_code', 'rules_version_mismatch');

        $this->assertSame($stored, $run->fresh()->state);
        $this->assertDatabaseCount('runs', 1);
        $this->assertDatabaseCount('run_actions', 0);
    }

    private function legacyRun(): Run
    {
        $id = $this->postJson('/api/v1/runs', ['level_id' => 'empty-cup'])
            ->assertCreated()->json('data.run_id');
        $run = Run::query()->where('public_id', $id)->firstOrFail();

        // Serialized 1.0.0 shape from ea5174b; deliberately independent of today's engine.
        $run->forceFill([
            'rules_version' => '1.0.0',
            'version' => 2,
            'state' => [
                'turn' => 2, 'max_turns' => 10, 'core_resilience' => 88,
                'defenses' => ['water' => 24, 'heat' => 30, 'land' => 30],
                'sigils' => ['water' => 1, 'heat' => 0, 'land' => 0],
                'resistance' => ['water' => 0, 'heat' => 0, 'land' => 0],
                'cooldowns' => ['probe.water' => 2], 'shields' => [],
                'malice' => 7, 'malice_cap' => 12, 'sigil_cap' => 5,
                'combo_chain' => ['water'], 'breach_available' => false,
                'breached_elements' => [], 'last_attack_element' => 'water',
                'intent' => null, 'phase' => 'standard', 'flags' => [],
                'version' => 2, 'outcome' => 'in_progress',
            ],
        ])->save();

        return $run;
    }
}
