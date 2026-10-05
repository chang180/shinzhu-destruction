<?php

namespace Tests\Feature\Game;

use App\Domain\Game\BattleEvent;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Services\Game\DifficultyReport;
use Tests\TestCase;

/**
 * 關卡幕次切換的引擎行為。用測試專用的 empty-cup 變體：第一幕固定為 3.1.0 的單一幕預告表，
 * 再接上各測試需要的幕，所以這裡驗的是引擎，不受正式關卡數值調整影響。
 */
class LevelPhaseTransitionTest extends TestCase
{
    use PlaysCards;

    private const SHIELD_NINE = ['type' => 'shield', 'element' => 'water', 'magnitude' => 9, 'interruptible' => true];

    private const MAIN = [
        'id' => 'main',
        'label' => '全關',
        'objective' => '現在出破陣，或留擾序等修復窗口。',
        'starts_when' => ['type' => 'turn_gte', 'value' => 1],
        'intents' => [
            3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 14, 'interruptible' => true],
            6 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 16, 'interruptible' => true],
        ],
        'default_intent' => ['type' => 'reinforce', 'element' => 'water', 'magnitude' => 4, 'interruptible' => false],
    ];

    /**
     * @param  list<array<string, mixed>>  $extraPhases
     */
    private function variant(array $extraPhases): LevelDefinition
    {
        $config = config('game.levels.empty-cup');
        $config['level_phases'] = [self::MAIN, ...$extraPhases];

        return LevelDefinition::fromConfig('empty-cup', $config);
    }

    /**
     * @param  array<string, mixed>  $startsWhen
     * @param  array<string, mixed>|null  $defaultIntent
     * @return array<string, mixed>
     */
    private function phase(string $id, array $startsWhen, ?array $defaultIntent = null, ?array $intents = null): array
    {
        $main = self::MAIN;

        return [
            'id' => $id,
            'label' => "幕 {$id}",
            'objective' => "{$id} 的目標",
            'starts_when' => $startsWhen,
            'intents' => $intents ?? $main['intents'],
            'default_intent' => $defaultIntent ?? $main['default_intent'],
        ];
    }

    /**
     * 每回合蓄勢，回傳每次出牌後的 [局面, 事件]。
     *
     * @return list<array{0: BattleState, 1: list<BattleEvent>}>
     */
    private function gatherTurns(LevelDefinition $level, int $turns): array
    {
        $state = $this->engine()->start($level, 20261005);
        $results = [];

        for ($i = 0; $i < $turns; $i++) {
            [$state, $events] = $this->act($state, 'gather', $level);
            $results[] = [$state, $events];
        }

        return $results;
    }

    /**
     * @param  list<BattleEvent>  $events
     * @return list<string>
     */
    private function types(array $events): array
    {
        return array_map(static fn (BattleEvent $event): string => $event->type, $events);
    }

    public function test_enters_the_next_phase_at_the_turn_boundary_and_uses_its_intents_from_the_next_turn(): void
    {
        $level = $this->variant([$this->phase('second', ['type' => 'turn_gte', 'value' => 3], self::SHIELD_NINE, [])]);

        [[$afterTurnOne, $turnOneEvents], [$afterTurnTwo, $turnTwoEvents]] = $this->gatherTurns($level, 2);

        $this->assertNotContains('level_phase_change', $this->types($turnOneEvents));
        $this->assertSame('main', $afterTurnOne->levelPhaseId);

        $types = $this->types($turnTwoEvents);
        $change = $turnTwoEvents[array_search('level_phase_change', $types, true)];
        $this->assertSame('level_phase_change', $types[count($types) - 2]);
        $this->assertSame('turn_advanced', $types[count($types) - 1]);
        $this->assertSame(['level_phase_id' => 'main'], $change->before);
        $this->assertSame('second', $change->after['level_phase_id']);
        $this->assertSame(3, $change->after['from_turn']);
        $this->assertSame('second 的目標', $change->after['objective']);
        $this->assertSame('level_phase_turn_gte', $change->reasonCode);
        $this->assertSame(2, $change->turn);

        $this->assertSame('second', $afterTurnTwo->levelPhaseId);
        $this->assertSame(3, $afterTurnTwo->turn);
        $this->assertSame('shield', $afterTurnTwo->intent->type);
        $this->assertSame(9, $afterTurnTwo->intent->magnitude);
    }

    public function test_the_intent_already_shown_for_the_current_turn_is_not_replaced_by_a_phase_change(): void
    {
        // 第 3 回合的修復 14 在第 2 回合結束時就展示了。第 3 回合破陣讓核心掉到門檻內，
        // 城市仍照展示的預告修復，切幕排在修復之後、推進回合之前，新預告表從第 4 回合起用。
        $level = $this->variant([$this->phase('second', ['type' => 'core_lte', 'value' => 95], self::SHIELD_NINE, [])]);
        [, [$state]] = $this->gatherTurns($level, 2);
        $this->assertSame('repair', $state->intent->type);

        [$after, $events] = $this->act($state, 'breach.water', $level);

        $types = $this->types($events);
        $this->assertLessThan(array_search('level_phase_change', $types, true), array_search('city_repair', $types, true));
        $this->assertSame(14, collect($events)->firstWhere('type', 'city_repair')->delta['core_resilience']);
        $this->assertSame('second', $after->levelPhaseId);
        $this->assertSame(4, $after->turn);
        $this->assertSame('shield', $after->intent->type);
    }

    public function test_a_phase_change_resets_nothing_but_the_phase_and_the_next_intent_table(): void
    {
        $same = $this->variant([$this->phase('second', ['type' => 'turn_gte', 'value' => 3])]);

        $withPhase = $this->gatherTurns($same, 4);
        $without = $this->gatherTurns($this->variant([]), 4);

        foreach ([0, 1, 2, 3] as $turn) {
            $expected = $without[$turn][0]->toArray();
            $actual = $withPhase[$turn][0]->toArray();
            $this->assertSame(array_diff_key($expected, ['level_phase_id' => 1]), array_diff_key($actual, ['level_phase_id' => 1]), "turn {$turn}");

            $events = array_values(array_filter($withPhase[$turn][1], static fn (BattleEvent $event): bool => $event->type !== 'level_phase_change'));
            $this->assertSame(
                array_map(static fn (BattleEvent $event): array => array_diff_key($event->toArray(), ['sequence' => 1]), $without[$turn][1]),
                array_map(static fn (BattleEvent $event): array => array_diff_key($event->toArray(), ['sequence' => 1]), $events),
            );
        }

        $this->assertSame('second', $withPhase[3][0]->levelPhaseId);
    }

    public function test_advances_at_most_one_phase_per_turn_boundary_and_never_goes_back(): void
    {
        $level = $this->variant([
            $this->phase('second', ['type' => 'turn_gte', 'value' => 2]),
            $this->phase('third', ['type' => 'core_lte', 'value' => 100]),
        ]);

        $phases = array_map(static fn (array $result): ?string => $result[0]->levelPhaseId, $this->gatherTurns($level, 4));

        $this->assertSame(['second', 'third', 'third', 'third'], $phases);
    }

    public function test_core_and_flag_triggers_wait_until_the_run_actually_reaches_them(): void
    {
        $level = $this->variant([$this->phase('second', ['type' => 'any_of', 'of' => [['type' => 'flag_true', 'flag' => 'first_interrupt_done'], ['type' => 'core_lte', 'value' => 10]]])]);

        $phases = array_map(static fn (array $result): ?string => $result[0]->levelPhaseId, $this->gatherTurns($level, 3));
        $this->assertSame(['main', 'main', 'main'], $phases);

        $state = $this->engine()->start($level, 20261005);
        [$state] = $this->act($state, 'gather', $level);
        [$state] = $this->act($state, 'gather', $level);
        [$state, $events] = $this->act($state, 'disrupt.water', $level);

        $this->assertContains('interrupt', $this->types($events));
        $this->assertSame('second', $state->levelPhaseId);
        $this->assertSame('level_phase_any_of', collect($events)->firstWhere('type', 'level_phase_change')->reasonCode);
    }

    public function test_a_saved_and_restored_run_keeps_its_phase_and_continues_identically(): void
    {
        $level = $this->variant([$this->phase('second', ['type' => 'turn_gte', 'value' => 3], self::SHIELD_NINE, [])]);
        [, [$state]] = $this->gatherTurns($level, 2);

        $restored = BattleState::fromArray(json_decode(json_encode($state->toArray()), true));
        [$continued, $continuedEvents] = $this->act($state, 'gather', $level);
        [$fromSave, $fromSaveEvents] = $this->act($restored, 'gather', $level);

        $this->assertSame('second', $restored->levelPhaseId);
        $this->assertSame($continued->toArray(), $fromSave->toArray());
        $this->assertSame(
            array_map(static fn (BattleEvent $event): array => $event->toArray(), $continuedEvents),
            array_map(static fn (BattleEvent $event): array => $event->toArray(), $fromSaveEvents),
        );
    }

    public function test_the_simulator_counts_overhaul_state_changes_separately_from_level_phase_changes(): void
    {
        // 7.0.0 實際獎勵牌組的中止重整通關樣本；兩種事件不能互相冒充。
        $level = app(LevelRepository::class)->get('stored-night');
        $modifiers = EquivalenceRecorder::modifiers(0.0);
        $deck = app(DifficultyReport::class)->deckVariants($level)['tide-siege+hollow-ground'];

        $result = (new BattleSimulator($this->engine()))->run($level, $modifiers, new PlannerStrategy($modifiers, app(CardCatalog::class)), 134, 'w0h0l0', $deck);

        $this->assertSame(['overhaul_started', 'overhaul_stopped'], array_column($result->phaseChanges, 'reason_code'));
        $this->assertSame(['overhaul-warning', 'last-night'], array_column($result->levelPhaseChanges, 'to'));
        $this->assertTrue($result->won());
    }
}
