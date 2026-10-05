<?php

namespace App\Domain\Game\Simulation;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleEvent;
use App\Domain\Game\BattleState;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\TurnResult;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * 跑完一整局自動對戰。
 *
 * seed 同時決定牌序與策略的選擇，所以 (關卡, 情境, 策略, seed) 完全決定結果，
 * 任何一場都能重跑。
 *
 * 模擬一律跑不限時：策略沒有反應速度問題，逾時只會測到「我沒有送出行動」，
 * 量不到打法好壞。揭牌不是策略決策，由模擬器自動送出。
 */
class BattleSimulator
{
    public function __construct(private readonly BattleEngine $engine) {}

    /**
     * @param  array<string, int>|null  $composition  牌組組成（牌型 => 張數）；null 用關卡起始牌組
     * @param  string  $deckLabel  牌組版本標籤，只寫進結果供報告聚合
     */
    public function run(
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
        Strategy $strategy,
        int $seed,
        string $scenarioLabel,
        ?array $composition = null,
        string $deckLabel = 'starter',
    ): SimulationResult {
        return $this->play(
            $this->engine->start($level, $seed, $composition),
            $level,
            $modifiers,
            $strategy,
            $seed,
            $scenarioLabel,
            $deckLabel,
        );
    }

    /**
     * 從一個已存在的局面（不一定是關卡開局）繼續跑到結束。P07 反事實比較用
     * 這個入口：在某一回合換一個行動之後，剩下的回合交給策略接著打，
     * 結果和一般模擬一樣完全由 (局面, seed) 決定。
     */
    public function continueFrom(
        BattleState $state,
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
        Strategy $strategy,
        int $seed,
        string $scenarioLabel,
    ): SimulationResult {
        return $this->play($state, $level, $modifiers, $strategy, $seed, $scenarioLabel);
    }

    private function play(
        BattleState $state,
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
        Strategy $strategy,
        int $seed,
        string $scenarioLabel,
        string $deckLabel = 'starter',
    ): SimulationResult {
        $rng = new Randomizer(new Xoshiro256StarStar($this->seedString($seed)));
        $actions = [];
        $rejected = 0;
        $counter = 0;
        $metrics = self::emptyMetrics();
        $phaseChanges = [];
        $levelPhaseChanges = [];
        $ultimateTurns = [];
        $mirrorShield = null;

        if ($strategy instanceof InstrumentedStrategy) {
            $strategy->beginGame();
        }

        while (! $state->outcome->isFinished()) {
            if ($state->turnPhase === BattleState::PHASE_AWAITING_REVEAL) {
                $result = $this->engine->apply(
                    $state,
                    new ActionRequest('sim-reveal-'.(++$counter), $state->version, ActionType::Reveal),
                    $level,
                    $modifiers,
                );
                $this->measure($state, $result, $level, $metrics, $phaseChanges, $levelPhaseChanges, $ultimateTurns, $mirrorShield);
                $state = $result->state;

                continue;
            }

            $choice = $strategy->choose($state, $this->engine, $level, $rng);

            if ($choice === null) {
                break;
            }

            $request = $this->request('sim-'.(++$counter), $state, $choice);

            try {
                $result = $this->engine->apply($state, $request, $level, $modifiers);
            } catch (InvalidActionException) {
                // 策略挑了非法動作是策略的錯，不是引擎的；記錄並退回蓄勢，
                // 避免無限迴圈把問題藏起來。
                $rejected++;

                if ($rejected > $level->maxTurns * 2) {
                    break;
                }

                $choice = ['type' => 'play', 'card_id' => null, 'fixed' => 'gather', 'skill_id' => 'gather', 'target' => null];
                $result = $this->engine->apply(
                    $state,
                    $this->request('sim-fallback-'.$counter, $state, $choice),
                    $level,
                    $modifiers,
                );
            }

            $actions[] = [
                'turn' => $state->turn,
                'type' => $choice['type'],
                'card_id' => $choice['card_id'] ?? null,
                'skill_id' => $choice['skill_id'] ?? null,
                'target' => $choice['target'] ?? null,
            ];
            $this->measure($state, $result, $level, $metrics, $phaseChanges, $levelPhaseChanges, $ultimateTurns, $mirrorShield);
            $state = $result->state;
        }

        if ($state->breachAvailable) {
            $metrics['breaches_wasted']++;
        }

        return new SimulationResult(
            levelId: $level->id,
            strategy: $strategy->name(),
            scenario: $scenarioLabel,
            seed: $seed,
            outcome: $state->outcome,
            turns: $state->turn,
            coreRemaining: $state->coreResilience,
            rejectedActions: $rejected,
            actions: $actions,
            deck: $deckLabel,
            maxTurns: $level->maxTurns,
            metrics: $metrics,
            phaseChanges: $phaseChanges,
            levelPhaseChanges: $levelPhaseChanges,
            strategyReport: $strategy instanceof InstrumentedStrategy ? $strategy->gameReport() : [],
            ultimateTurns: $ultimateTurns,
            terminalMalice: $state->outcome->isFinished() ? $state->malice : null,
        );
    }

    /**
     * 量測欄位的初始值。全部由引擎事件統計，不重算任何規則。
     *
     * @return array<string, int>
     */
    public static function emptyMetrics(): array
    {
        return [
            'city_repairs' => 0,
            'city_repair_amount' => 0,
            'shields_raised' => 0,
            'shield_absorbed' => 0,
            'interrupts' => 0,
            'interrupts_failed' => 0,
            'repairs_interrupted' => 0,
            'shields_interrupted' => 0,
            'breaches_opened' => 0,
            'breaches_used' => 0,
            'breaches_wasted' => 0,
            'combos' => 0,
            'pulse_refunds' => 0,
            'overhaul_started' => 0,
            'overhaul_stopped' => 0,
            'overhaul_completed' => 0,
            'missed_actions' => 0,
            'ultimate_uses' => 0,
            'pulse_windows' => 0,
            'repair_windows' => 0,
            'mirror_baits' => 0,
            'mirror_followup_windows' => 0,
            'mirror_switch_hits' => 0,
            'mirror_switch_breaches' => 0,
            'mirror_switch_core_damage' => 0,
        ];
    }

    /**
     * 從一次結算的事件累計量測欄位。
     *
     * 破綻浪費：被逾時吃掉、用在完全被護盾吸收的攻擊上，或到結束仍未使用
     * （最後一項在對局結束時另計）。
     *
     * @param  array<string, int>  $metrics
     *                                       機制狀態（$state->phase，第 5 關重整）與關卡幕次（$state->levelPhaseId）分開記錄，
     *                                       重整啟動不會被算成進入下一幕。
     * @param  list<array{turn: int, from: string, to: string, reason_code: string}>  $phaseChanges
     * @param  list<array{turn: int, from: string|null, to: string, reason_code: string}>  $levelPhaseChanges
     * @param  array{turn: int, element: string}|null  $mirrorShield
     * @param  list<int>  $ultimateTurns
     */
    private function measure(BattleState $before, TurnResult $result, LevelDefinition $level, array &$metrics, array &$phaseChanges, array &$levelPhaseChanges, array &$ultimateTurns, ?array &$mirrorShield): void
    {
        foreach ($result->events as $event) {
            if ($event->type === 'sigil_spent' && $event->reasonCode === 'ultimate_consumed_all') {
                $ultimateTurns[] = $before->turn;
            }
            if ($event->type === 'action_accepted' || $event->type === 'action_missed') {
                $metrics['repair_windows'] += $before->intent?->type === 'repair' ? 1 : 0;
                $metrics['pulse_windows'] += $level->apostlePower === 'pulse_combo_refund' && $level->isPulseTurn($before->turn) ? 1 : 0;
            }
            if ($event->type === 'level_phase_change') {
                $levelPhaseChanges[] = [
                    'turn' => $before->turn,
                    'from' => $event->before['level_phase_id'] ?? null,
                    'to' => $event->after['level_phase_id'],
                    'reason_code' => $event->reasonCode,
                ];
            }
        }

        $this->measureMirror($before, $result, $level, $metrics, $mirrorShield);

        $pendingBreach = false;

        foreach ($result->events as $event) {
            $this->count($event, $metrics, $pendingBreach);
        }

        if ($result->state->phase !== $before->phase) {
            $reason = 'phase_changed';

            foreach ($result->events as $event) {
                if ($event->type === 'phase_change') {
                    $reason = $event->reasonCode;
                }
            }

            $phaseChanges[] = [
                'turn' => $before->turn,
                'from' => $before->phase,
                'to' => $result->state->phase,
                'reason_code' => $reason,
            ];
        }
    }

    /**
     * Attribute only observed mirrored city shields, followed immediately by an actual core hit.
     * A static shield, a forecast without a raised shield, an ultimate or an expired shield is not a lure.
     *
     * @param  array<string, int>  $metrics
     * @param  array{turn: int, element: string}|null  $mirrorShield
     */
    private function measureMirror(BattleState $before, TurnResult $result, LevelDefinition $level, array &$metrics, ?array &$mirrorShield): void
    {
        $settled = array_any($result->events, static fn (BattleEvent $event): bool => in_array($event->type, ['action_accepted', 'action_missed'], true));
        if (! $settled) {
            return;
        }

        $standing = $mirrorShield !== null && $before->turn === $mirrorShield['turn'] + 1
            && array_any($before->shields, static fn (array $shield): bool => $shield['element'] === $mirrorShield['element'] && $shield['amount'] > 0 && $shield['expires_on_turn'] > $before->turn);
        if ($standing) {
            $metrics['mirror_followup_windows']++;
            foreach ($result->events as $event) {
                if ($event->type === 'impact' && $event->target !== null && $event->target !== $mirrorShield['element'] && ($event->delta['core_resilience'] ?? 0) < 0) {
                    $metrics['mirror_switch_hits']++;
                    $metrics['mirror_switch_core_damage'] -= (int) $event->delta['core_resilience'];
                    $metrics['mirror_switch_breaches'] += $event->cueId === 'cue.impact.breach' ? 1 : 0;
                }
            }
        }
        $mirrorShield = null;

        foreach ($result->events as $event) {
            if ($event->type === 'city_shield' && $before->lastAttackElement !== null && $event->target === $before->lastAttackElement && $level->mirrorsPlayerOnTurn($before->turn, $before->levelPhaseId)) {
                $metrics['mirror_baits']++;
                $mirrorShield = ['turn' => $before->turn, 'element' => $event->target];
            }
        }
    }

    /**
     * @param  array<string, int>  $metrics
     */
    private function count(BattleEvent $event, array &$metrics, bool &$pendingBreach): void
    {
        switch ($event->type) {
            case 'sigil_spent':
                $metrics['ultimate_uses'] += $event->reasonCode === 'ultimate_consumed_all' ? 1 : 0;
                break;
            case 'city_repair':
                $metrics['city_repairs']++;
                $metrics['city_repair_amount'] += (int) ($event->delta['core_resilience'] ?? 0);
                break;
            case 'city_shield':
                $metrics['shields_raised']++;
                break;
            case 'shield_absorbed':
                $metrics['shield_absorbed'] += (int) ($event->delta['absorbed'] ?? 0);
                break;
            case 'interrupt':
                $metrics['interrupts']++;
                $intentType = $event->before['intent']['type'] ?? null;

                if ($intentType === 'repair') {
                    $metrics['repairs_interrupted']++;
                } elseif ($intentType === 'shield') {
                    $metrics['shields_interrupted']++;
                }
                break;
            case 'interrupt_failed':
                $metrics['interrupts_failed']++;
                break;
            case 'breach_opened':
                $metrics['breaches_opened']++;
                break;
            case 'breach_consumed':
                if ($event->reasonCode === 'breach_window_used') {
                    $metrics['breaches_used']++;
                    $pendingBreach = true;
                } else {
                    $metrics['breaches_wasted']++;
                }
                break;
            case 'impact':
                if ($pendingBreach && (int) ($event->delta['core_resilience'] ?? 0) === 0) {
                    $metrics['breaches_wasted']++;
                }
                $pendingBreach = false;
                break;
            case 'combo':
                $metrics['combos']++;
                break;
            case 'malice_refund':
                if ($event->reasonCode === 'apostle_pulse_combo') {
                    $metrics['pulse_refunds']++;
                }
                break;
            case 'phase_change':
                $key = match ($event->reasonCode) {
                    'overhaul_started', 'overhaul_stopped', 'overhaul_completed' => $event->reasonCode,
                    default => null,
                };

                if ($key !== null) {
                    $metrics[$key]++;
                }
                break;
            case 'action_missed':
                $metrics['missed_actions']++;
                break;
        }
    }

    /**
     * 把策略挑到的行動描述轉成提交。留牌交給策略決定，沒有指定就全部棄掉。
     *
     * @param  array<string, mixed>  $choice
     */
    private function request(string $actionId, BattleState $state, array $choice): ActionRequest
    {
        return new ActionRequest(
            actionId: $actionId,
            expectedVersion: $state->version,
            type: ActionType::from($choice['type'] ?? 'play'),
            cardId: $choice['card_id'] ?? null,
            fixedSkillId: $choice['fixed'] ?? null,
            keep: $choice['keep'] ?? [],
        );
    }

    private function seedString(int $seed): string
    {
        return hash('sha256', 'shinzhu:'.$seed, true);
    }
}
