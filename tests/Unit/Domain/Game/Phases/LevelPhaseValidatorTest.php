<?php

namespace Tests\Unit\Domain\Game\Phases;

use App\Domain\Game\Exceptions\InvalidLevelConfigException;
use App\Domain\Game\LevelDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LevelPhaseValidatorTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function phase(string $id, array $startsWhen, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'label' => $id,
            'objective' => "{$id} objective",
            'starts_when' => $startsWhen,
            'intents' => [3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 14, 'interruptible' => true]],
            'default_intent' => ['type' => 'reinforce', 'element' => 'water', 'magnitude' => 4, 'interruptible' => false],
        ];
    }

    /**
     * @param  list<mixed>  $phases
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function level(array $phases, array $overrides = []): array
    {
        return $overrides + [
            'sequence' => 1, 'tier' => 'main', 'available' => true, 'name' => 'test', 'subtitle' => '', 'apostle' => 'test',
            'max_turns' => 8, 'requires' => null, 'defenses' => ['water' => 30, 'heat' => 26, 'land' => 26],
            'data_elements' => ['water'], 'deck' => ['long-flow' => 3], 'mechanic' => '', 'lesson' => '', 'briefing' => [],
            'apostle_power' => 'interrupt_refund', 'apostle_power_value' => 2,
            'level_phases' => $phases,
        ];
    }

    private static function first(): array
    {
        return self::phase('opening', ['type' => 'turn_gte', 'value' => 1]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): array
    {
        $first = self::first();

        return [
            'no phases' => [self::level([]), 'phase_missing_first'],
            'duplicate id' => [self::level([$first, self::phase('opening', ['type' => 'turn_gte', 'value' => 3])]), 'phase_duplicate_id'],
            'first phase does not start at turn 1' => [self::level([self::phase('opening', ['type' => 'turn_gte', 'value' => 2])]), 'phase_first_not_initial'],
            'unknown trigger type' => [self::level([$first, self::phase('two', ['type' => 'turn_between', 'value' => 3])]), 'trigger_unknown_type'],
            'trigger value is not an integer' => [self::level([$first, self::phase('two', ['type' => 'turn_gte', 'value' => '3'])]), 'trigger_field_type'],
            'trigger has an unknown field' => [self::level([$first, self::phase('two', ['type' => 'core_lte', 'value' => 50, 'phase' => 'opening'])]), 'trigger_unknown_field'],
            'turn order goes backwards' => [self::level([$first, self::phase('two', ['type' => 'turn_gte', 'value' => 5]), self::phase('three', ['type' => 'turn_gte', 'value' => 4])]), 'phase_order_regression'],
            'missing default intent' => [self::level([array_diff_key($first, ['default_intent' => 1])]), 'phase_missing_default_intent'],
            'invalid intent element' => [self::level([self::phase('opening', ['type' => 'turn_gte', 'value' => 1], ['default_intent' => ['type' => 'reinforce', 'element' => 'fire', 'magnitude' => 4, 'interruptible' => false]])]), 'intent_invalid'],
            'overhaul cannot be scheduled' => [self::level([self::phase('opening', ['type' => 'turn_gte', 'value' => 1], ['intents' => [2 => ['type' => 'overhaul', 'element' => 'water', 'magnitude' => 4, 'interruptible' => true]]])]), 'intent_invalid'],
            'flag the engine never sets' => [self::level([$first, self::phase('two', ['type' => 'flag_true', 'flag' => 'boss_angry'])]), 'trigger_unknown_flag'],
            'flag this level never sets' => [self::level([$first, self::phase('two', ['type' => 'flag_true', 'flag' => 'overhaul_started'])]), 'trigger_flag_not_settable'],
            'turn beyond the last turn' => [self::level([$first, self::phase('two', ['type' => 'turn_gte', 'value' => 9])]), 'phase_unreachable'],
            'core at zero ends the run first' => [self::level([$first, self::phase('two', ['type' => 'core_lte', 'value' => 0])]), 'phase_unreachable'],
            'empty any_of' => [self::level([$first, self::phase('two', ['type' => 'any_of', 'of' => []])]), 'trigger_empty_any_of'],
            'any_of with only unreachable children' => [self::level([$first, self::phase('two', ['type' => 'any_of', 'of' => [['type' => 'turn_gte', 'value' => 20], ['type' => 'core_lte', 'value' => 0]]])]), 'phase_unreachable'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('invalidConfigs')]
    public function test_rejects_invalid_phase_configuration(array $config, string $reasonCode): void
    {
        try {
            LevelDefinition::fromConfig('test-level', $config);
            $this->fail("Expected {$reasonCode}");
        } catch (InvalidLevelConfigException $exception) {
            $this->assertSame($reasonCode, $exception->reasonCode);
            $this->assertSame('test-level', $exception->levelId);
        }
    }

    public function test_a_single_phase_level_loads_with_its_intents(): void
    {
        $level = LevelDefinition::fromConfig('test-level', self::level([self::first()]));

        $this->assertSame(['opening'], array_map(static fn ($phase): string => $phase->id, $level->levelPhases));
        $this->assertSame(14, $level->intentForTurn(3)->magnitude);
        $this->assertSame('reinforce', $level->intentForTurn(4)->type);
    }

    public function test_a_flag_phase_is_structurally_reachable_even_if_a_given_run_never_sets_the_flag(): void
    {
        $level = LevelDefinition::fromConfig('test-level', self::level([
            self::first(),
            self::phase('after-interrupt', ['type' => 'any_of', 'of' => [['type' => 'flag_true', 'flag' => 'first_interrupt_done'], ['type' => 'core_lte', 'value' => 40]]]),
        ]));

        $this->assertSame('首次成功打斷之後，或城市核心降到 40 以下', $level->publicPhases()[1]['starts_when_summary']);
        $this->assertSame($level->publicPhases()[1]['starts_when_summary'], $level->publicPhases()[0]['next_phase_summary']);
    }

    public function test_an_overhaul_level_keeps_its_mechanic_states_alongside_level_phases(): void
    {
        $level = LevelDefinition::fromConfig('test-level', self::level(
            [self::first(), self::phase('alarm', ['type' => 'flag_true', 'flag' => 'overhaul_started'])],
            ['overhaul' => ['trigger_core' => 50, 'countdown_turns' => 2, 'repair_magnitude' => 48, 'required_interrupts' => 2], 'mechanic_states' => ['standby', 'overhaul']],
        ));

        $this->assertSame(['standby', 'overhaul'], $level->mechanicStates);
        $this->assertSame(['opening', 'alarm'], array_map(static fn ($phase): string => $phase->id, $level->levelPhases));
    }
}
