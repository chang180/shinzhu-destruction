<?php

namespace App\Domain\Game;

use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\Scenario\ScenarioModifiers;

/**
 * 唯一的規則計算來源。
 *
 * 完全確定性：同一組（關卡、情境修正、行動序列）永遠得到同一串事件與結局。
 * rules_version 1.0.0 的城市行為由關卡預告表決定，沒有任何亂數，所以重播
 * 不需要保存亂數狀態。
 *
 * 結算順序固定為 GAME-DESIGN §3.3：
 * 驗證 → 扣惡意 → 套修正 → 算核心衝擊 → 扣防線 → 更新印記／連攜／打斷 →
 * 核心歸零即勝 → 城市回應 → 更新效果與冷卻 → 檢查回合耗盡 → 產生下回合預告與回復。
 */
class BattleEngine
{
    private int $sequence = 0;

    /**
     * 這次結算所屬的回合。同一次行動產生的所有事件都掛在這個回合底下，
     * 包含最後的回合推進——否則依 turn 分組的重播與演出會把回合推進
     * 歸到下一回合，複盤也會把「第幾回合做了什麼」講錯。
     */
    private int $actionTurn = 1;

    /** @var list<BattleEvent> */
    private array $events = [];

    /**
     * @param  array<string, mixed>  $config  config/game.php
     */
    public function __construct(
        private readonly SkillCatalog $skills,
        private readonly array $config,
    ) {}

    public function rulesVersion(): string
    {
        return $this->config['rules_version'];
    }

    public function start(LevelDefinition $level): BattleState
    {
        $initial = $this->config['initial'];

        return new BattleState(
            turn: 1,
            maxTurns: $level->maxTurns,
            coreResilience: $initial['core_resilience'],
            defenses: $level->defenses,
            sigils: array_fill_keys(Element::values(), 0),
            resistance: array_fill_keys(Element::values(), 0),
            cooldowns: [],
            shields: [],
            malice: $initial['malice'],
            maliceCap: $initial['malice_cap'],
            sigilCap: $initial['sigil_cap'],
            comboChain: [],
            breachAvailable: false,
            breachedElements: [],
            lastAttackElement: null,
            intent: $level->intentForTurn(1),
            phase: $level->phases === [] ? 'standard' : $level->phases[0],
            flags: [],
            version: 1,
            outcome: Outcome::InProgress,
        );
    }

    /**
     * @throws InvalidActionException 在扣任何資源之前丟出
     */
    public function apply(
        BattleState $state,
        ActionRequest $action,
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
    ): TurnResult {
        $skill = $this->validate($state, $action);

        $this->sequence = 0;
        $this->events = [];
        $this->actionTurn = $state->turn;

        $next = $state->copy();

        $this->spendMalice($next, $skill);

        if ($skill->kind === SkillKind::Gather) {
            $this->resolveGather($next, $skill);
        } else {
            $this->resolveOffensive($next, $skill, $level, $modifiers);
        }

        $interrupted = $this->resolveInterrupt($next, $skill, $level);

        if ($next->coreResilience <= 0) {
            $this->finish($next, Outcome::PlayerVictory, 'core_depleted');

            return new TurnResult($next, $this->events);
        }

        $this->resolveCityResponse($next, $level, $interrupted);

        if ($next->coreResilience <= 0) {
            $this->finish($next, Outcome::PlayerVictory, 'core_depleted');

            return new TurnResult($next, $this->events);
        }

        $this->expireEffects($next);

        if ($next->turn >= $next->maxTurns) {
            $this->finish($next, Outcome::CityHeld, 'turns_exhausted');

            return new TurnResult($next, $this->events);
        }

        $this->advanceTurn($next, $level);

        return new TurnResult($next, $this->events);
    }

    /**
     * 目前可以合法送出的行動。前端用它決定按鈕狀態，模擬策略用它挑動作；
     * 兩者都不重算規則，只讀這份清單。
     *
     * @return list<array{skill_id: string, target: string|null, reason: string|null}>
     */
    public function availableActions(BattleState $state): array
    {
        $actions = [];

        foreach ($this->skills->all() as $skill) {
            $targets = $skill->kind->isElemental() ? Element::all() : [null];

            foreach ($targets as $target) {
                $request = new ActionRequest('probe', $state->version, $skill->id, $target);

                try {
                    $this->validate($state, $request);
                    $reason = null;
                } catch (InvalidActionException $exception) {
                    $reason = $exception->reasonCode;
                }

                $actions[] = [
                    'skill_id' => $skill->id,
                    'target' => $target?->value,
                    'reason' => $reason,
                ];
            }
        }

        return $actions;
    }

    /**
     * @return list<array{skill_id: string, target: string|null}>
     */
    public function legalActions(BattleState $state): array
    {
        return array_values(array_map(
            static fn (array $action): array => ['skill_id' => $action['skill_id'], 'target' => $action['target']],
            array_filter($this->availableActions($state), static fn (array $action): bool => $action['reason'] === null),
        ));
    }

    /**
     * 驗證只讀狀態，不改任何東西。任何一項不過就丟例外，回合與惡意都不消耗。
     */
    private function validate(BattleState $state, ActionRequest $action): Skill
    {
        if ($state->outcome->isFinished()) {
            throw InvalidActionException::runFinished();
        }

        if (! $this->skills->has($action->skillId)) {
            throw InvalidActionException::unknownSkill($action->skillId);
        }

        $skill = $this->skills->get($action->skillId);

        if ($skill->kind->isElemental() && $action->target === null) {
            throw InvalidActionException::targetRequired($skill->id);
        }

        if (! $skill->kind->isElemental() && $action->target !== null) {
            throw InvalidActionException::targetNotAllowed($skill->id);
        }

        if (! $state->skillReady($skill->id)) {
            throw InvalidActionException::onCooldown($skill->id, $state->cooldowns[$skill->id]);
        }

        if ($state->malice < $skill->maliceCost) {
            throw InvalidActionException::notEnoughMalice($skill->maliceCost, $state->malice);
        }

        if ($skill->kind === SkillKind::Ultimate) {
            $missing = [];

            foreach (Element::all() as $element) {
                $shortfall = $skill->requiredSigilsPerElement - $state->sigil($element);

                if ($shortfall > 0) {
                    $missing[$element->value] = $shortfall;
                }
            }

            if ($missing !== []) {
                throw InvalidActionException::sigilsNotReady($missing);
            }
        }

        return $skill;
    }

    private function spendMalice(BattleState $state, Skill $skill): void
    {
        $before = $state->malice;
        $state->malice -= $skill->maliceCost;
        $state->cooldowns[$skill->id] = $state->turn + $skill->cooldown + 1;

        $this->record($state, 'action_accepted', BattleEvent::PLAYER, $skill->element?->value, 'malice_spent',
            ['malice' => $before],
            ['malice' => -$skill->maliceCost],
            ['malice' => $state->malice, 'ready_on_turn' => $state->cooldowns[$skill->id]],
            'cue.action.'.$skill->kind->value,
        );
    }

    /**
     * 蓄勢：回復惡意，並讓目前最高的一項適應抗性降 1 層。不造成傷害，中斷連攜。
     */
    private function resolveGather(BattleState $state, Skill $skill): void
    {
        $before = $state->malice;
        $state->malice = min($state->maliceCap, $state->malice + $skill->maliceRefund);

        $this->record($state, 'malice_recovered', BattleEvent::PLAYER, null, 'gather',
            ['malice' => $before],
            ['malice' => $state->malice - $before],
            ['malice' => $state->malice],
            'cue.action.gather',
        );

        $highest = null;
        $highestLayers = 0;

        foreach (Element::all() as $element) {
            if ($state->resistanceLayers($element) > $highestLayers) {
                $highest = $element;
                $highestLayers = $state->resistanceLayers($element);
            }
        }

        if ($highest !== null) {
            $state->resistance[$highest->value] = max(0, $highestLayers - $skill->resistanceRelief);

            $this->record($state, 'resistance_change', BattleEvent::PLAYER, $highest->value, 'gather_relief',
                ['layers' => $highestLayers],
                ['layers' => $state->resistance[$highest->value] - $highestLayers],
                ['layers' => $state->resistance[$highest->value]],
                'cue.resistance.relief',
            );
        }

        $state->comboChain = [];
    }

    private function resolveOffensive(
        BattleState $state,
        Skill $skill,
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
    ): void {
        $damage = $this->config['damage'];
        $isUltimate = $skill->kind === SkillKind::Ultimate;

        $comboMultiplier = 1.0;
        $comboTriggered = false;

        if (! $isUltimate && $this->completesCombo($state, $skill->element)) {
            $comboMultiplier *= $damage['combo_multiplier'];
            $comboTriggered = true;
        }

        $breachConsumed = false;

        if ($state->breachAvailable) {
            $comboMultiplier *= $damage['breach_multiplier'];
            $state->breachAvailable = false;
            $breachConsumed = true;
        }

        // 防線一律使用命中前的值，適應抗性也是命中前的層數。
        $effectiveDefense = $isUltimate
            ? $this->averageEffectiveDefense($state)
            : $state->effectiveDefense($skill->element, $this->config['resistance']['defense_per_layer']);

        $environment = $isUltimate
            ? $this->averageModifier($level, $modifiers)
            : ($level->affectsElement($skill->element) ? $modifiers->for($skill->element) : 0.0);

        $reduction = min($damage['defense_reduction_cap'], $effectiveDefense / $damage['defense_divisor']);
        $raw = (int) floor($skill->baseImpact * (1 + $environment) * $comboMultiplier * (1 - $reduction));
        $raw = max(0, $raw);

        $absorbed = $this->absorbWithShields($state, $raw);
        $coreBefore = $state->coreResilience;
        $coreDamage = max(0, min($raw - $absorbed, $coreBefore));
        $state->coreResilience = $coreBefore - $coreDamage;

        if ($comboTriggered) {
            $this->record($state, 'combo', BattleEvent::PLAYER, $skill->element?->value, 'three_element_chain',
                ['chain' => $state->comboChain],
                ['multiplier' => $damage['combo_multiplier']],
                ['chain' => $state->comboChain],
                'cue.combo.triple',
            );
        }

        if ($breachConsumed) {
            $this->record($state, 'breach_consumed', BattleEvent::PLAYER, $skill->element?->value, 'breach_window_used',
                ['breach_available' => true],
                ['multiplier' => $damage['breach_multiplier']],
                ['breach_available' => false],
                'cue.breach.consumed',
            );
        }

        $this->record($state, 'impact', BattleEvent::PLAYER, $skill->element?->value, 'core_impact',
            ['core_resilience' => $coreBefore, 'shield' => $absorbed + $state->totalShield()],
            [
                'raw' => $raw,
                'absorbed' => $absorbed,
                'core_resilience' => -$coreDamage,
                'effective_defense' => $effectiveDefense,
                'environment_modifier' => round($environment, 4),
                'combo_multiplier' => round($comboMultiplier, 4),
            ],
            ['core_resilience' => $state->coreResilience, 'shield' => $state->totalShield()],
            $isUltimate ? 'cue.impact.ultimate' : 'cue.impact.'.$skill->kind->value,
        );

        $this->applyDefenseDelta($state, $skill, $isUltimate);
        $this->applySigils($state, $skill);
        $this->applyResistance($state, $skill, $isUltimate);
        $this->updateComboChain($state, $skill, $isUltimate);
        $this->applyPulseRefund($state, $level, $comboTriggered);
    }

    private function averageEffectiveDefense(BattleState $state): int
    {
        $perLayer = $this->config['resistance']['defense_per_layer'];
        $total = 0;

        foreach (Element::all() as $element) {
            $total += $state->effectiveDefense($element, $perLayer);
        }

        return (int) round($total / count(Element::all()));
    }

    private function averageModifier(LevelDefinition $level, ScenarioModifiers $modifiers): float
    {
        $values = [];

        foreach (Element::all() as $element) {
            $values[] = $level->affectsElement($element) ? $modifiers->for($element) : 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function absorbWithShields(BattleState $state, int $raw): int
    {
        $absorbed = 0;
        $remaining = $raw;

        foreach ($state->shields as $index => $shield) {
            if ($remaining <= 0) {
                break;
            }

            $taken = min($shield['amount'], $remaining);
            $state->shields[$index]['amount'] = $shield['amount'] - $taken;
            $absorbed += $taken;
            $remaining -= $taken;
        }

        $state->shields = array_values(array_filter(
            $state->shields,
            static fn (array $shield): bool => $shield['amount'] > 0,
        ));

        if ($absorbed > 0) {
            $this->record($state, 'shield_absorbed', BattleEvent::CITY, null, 'shield_absorbed',
                ['shield' => $absorbed + $state->totalShield()],
                ['absorbed' => $absorbed],
                ['shield' => $state->totalShield()],
                'cue.shield.absorb',
            );
        }

        return $absorbed;
    }

    private function applyDefenseDelta(BattleState $state, Skill $skill, bool $isUltimate): void
    {
        $targets = $isUltimate ? Element::all() : [$skill->element];

        foreach ($targets as $element) {
            $before = $state->defense($element);
            $after = max(0, min(100, $before + $skill->defenseDelta));
            $state->defenses[$element->value] = $after;

            if ($after !== $before) {
                $this->record($state, 'defense_shift', BattleEvent::PLAYER, $element->value, 'defense_reduced',
                    ['defense' => $before],
                    ['defense' => $after - $before],
                    ['defense' => $after],
                    'cue.defense.break',
                );
            }

            $this->checkBreach($state, $element, $after);
        }
    }

    /**
     * 防線首次降到 0 開啟破綻。同一條已經為 0 的防線不能重複觸發，
     * 要回補到 rearm_defense 以上才重新武裝。
     */
    private function checkBreach(BattleState $state, Element $element, int $defense): void
    {
        if ($defense > 0) {
            return;
        }

        if ($state->breachedElements[$element->value] ?? false) {
            return;
        }

        $state->breachedElements[$element->value] = true;
        $state->breachAvailable = true;

        $this->record($state, 'breach_opened', BattleEvent::PLAYER, $element->value, 'defense_zeroed',
            ['breach_available' => false],
            ['defense' => 0],
            ['breach_available' => true],
            'cue.breach.opened',
        );
    }

    private function applySigils(BattleState $state, Skill $skill): void
    {
        if ($skill->kind === SkillKind::Ultimate) {
            $before = $state->sigils;
            $state->sigils = array_fill_keys(Element::values(), 0);

            $this->record($state, 'sigil_spent', BattleEvent::PLAYER, null, 'ultimate_consumed_all',
                ['sigils' => $before],
                ['sigils' => 'all'],
                ['sigils' => $state->sigils],
                'cue.sigil.spent',
            );

            return;
        }

        if ($skill->sigilGain <= 0 || $skill->element === null) {
            return;
        }

        $before = $state->sigil($skill->element);
        $after = min($state->sigilCap, $before + $skill->sigilGain);
        $state->sigils[$skill->element->value] = $after;

        if ($after !== $before) {
            $this->record($state, 'sigil_gain', BattleEvent::PLAYER, $skill->element->value, 'sigil_charged',
                ['sigils' => $before],
                ['sigils' => $after - $before],
                ['sigils' => $after],
                'cue.sigil.gain',
            );
        }
    }

    /**
     * 加層發生在本次命中之後，本次命中已經用過命中前的層數。
     */
    private function applyResistance(BattleState $state, Skill $skill, bool $isUltimate): void
    {
        if ($isUltimate || $skill->element === null) {
            return;
        }

        $max = $this->config['resistance']['max_layers'];
        $element = $skill->element;

        if ($state->lastAttackElement === $element->value) {
            $before = $state->resistanceLayers($element);
            $state->resistance[$element->value] = min($max, $before + 1);

            if ($state->resistance[$element->value] !== $before) {
                $this->record($state, 'resistance_change', BattleEvent::CITY, $element->value, 'same_element_repeat',
                    ['layers' => $before],
                    ['layers' => 1],
                    ['layers' => $state->resistance[$element->value]],
                    'cue.resistance.gain',
                );
            }
        } elseif ($state->lastAttackElement !== null) {
            $previous = Element::from($state->lastAttackElement);
            $before = $state->resistanceLayers($previous);
            $state->resistance[$previous->value] = max(0, $before - 1);

            if ($state->resistance[$previous->value] !== $before) {
                $this->record($state, 'resistance_change', BattleEvent::CITY, $previous->value, 'element_switched',
                    ['layers' => $before],
                    ['layers' => -1],
                    ['layers' => $state->resistance[$previous->value]],
                    'cue.resistance.decay',
                );
            }
        }

        $state->lastAttackElement = $element->value;
    }

    private function completesCombo(BattleState $state, ?Element $element): bool
    {
        if ($element === null) {
            return false;
        }

        $chain = array_slice($state->comboChain, -2);
        $chain[] = $element->value;

        return count($chain) === 3 && count(array_unique($chain)) === 3;
    }

    private function updateComboChain(BattleState $state, Skill $skill, bool $isUltimate): void
    {
        if ($isUltimate || $skill->element === null) {
            $state->comboChain = [];

            return;
        }

        $chain = $state->comboChain;
        $chain[] = $skill->element->value;
        $state->comboChain = array_slice($chain, -3);
    }

    /**
     * 饗表使徒：需求脈衝回合達成跨系連攜返還惡意 1。
     */
    private function applyPulseRefund(BattleState $state, LevelDefinition $level, bool $comboTriggered): void
    {
        if ($level->apostlePower !== 'pulse_combo_refund' || ! $comboTriggered || ! $level->isPulseTurn($state->turn)) {
            return;
        }

        $before = $state->malice;
        $state->malice = min($state->maliceCap, $before + $level->apostlePowerValue);

        if ($state->malice !== $before) {
            $this->record($state, 'malice_refund', BattleEvent::PLAYER, null, 'apostle_pulse_combo',
                ['malice' => $before],
                ['malice' => $state->malice - $before],
                ['malice' => $state->malice],
                'cue.apostle.pulse_refund',
            );
        }
    }

    /**
     * 擾序只能取消「可打斷且同系」的預告。
     */
    private function resolveInterrupt(BattleState $state, Skill $skill, LevelDefinition $level): bool
    {
        if ($skill->kind !== SkillKind::Disrupt || $state->intent === null) {
            return false;
        }

        if (! $state->intent->canBeInterruptedBy($skill->element)) {
            $this->record($state, 'interrupt_failed', BattleEvent::PLAYER, $skill->element->value, 'intent_not_interruptible',
                ['intent' => $state->intent->toArray()],
                [],
                ['intent' => $state->intent->toArray()],
                'cue.interrupt.failed',
            );

            return false;
        }

        $this->record($state, 'interrupt', BattleEvent::PLAYER, $skill->element->value, 'intent_cancelled',
            ['intent' => $state->intent->toArray()],
            [],
            ['intent' => null],
            'cue.interrupt.success',
        );

        $this->applyInterruptRewards($state, $level);

        return true;
    }

    private function applyInterruptRewards(BattleState $state, LevelDefinition $level): void
    {
        if ($level->apostlePower === 'interrupt_refund' && ! ($state->flags['first_interrupt_done'] ?? false)) {
            $state->flags['first_interrupt_done'] = true;
            $before = $state->malice;
            $state->malice = min($state->maliceCap, $before + $level->apostlePowerValue);

            $this->record($state, 'malice_refund', BattleEvent::PLAYER, null, 'apostle_first_interrupt',
                ['malice' => $before],
                ['malice' => $state->malice - $before],
                ['malice' => $state->malice],
                'cue.apostle.interrupt_refund',
            );
        }

        if ($state->phase !== 'overhaul' || $level->overhaul === null) {
            return;
        }

        $interrupts = $state->flags['overhaul_interrupt_elements'] ?? [];
        $element = $state->intent?->element->value;

        if ($element !== null && ! in_array($element, $interrupts, true)) {
            $interrupts[] = $element;
        }

        $state->flags['overhaul_interrupt_elements'] = $interrupts;

        if (count($interrupts) < $level->overhaul['required_interrupts']) {
            return;
        }

        // 蓄夜使徒：以不同系兩次干擾中止重整，換來全系破綻。
        $state->flags['overhaul_stopped'] = true;
        $state->phase = 'standby';
        $state->breachAvailable = true;
        $state->breachedElements = [];

        $this->record($state, 'phase_change', BattleEvent::CITY, null, 'overhaul_stopped',
            ['phase' => 'overhaul'],
            ['interrupts' => $interrupts],
            ['phase' => 'standby', 'breach_available' => true],
            'cue.phase.overhaul_stopped',
        );
    }

    private function resolveCityResponse(BattleState $state, LevelDefinition $level, bool $interrupted): void
    {
        $intent = $state->intent;

        if ($intent === null || $interrupted) {
            return;
        }

        /*
         * 重整要跑完倒數才生效。倒數中的回合只是預告，城市不做事——
         * 否則「兩回合倒數」等於第一回合就直接修好，玩家根本沒有第二次干擾機會。
         */
        if ($intent->type === CityIntent::TYPE_OVERHAUL && $state->turn < ($state->flags['overhaul_due_turn'] ?? PHP_INT_MAX)) {
            return;
        }

        match ($intent->type) {
            CityIntent::TYPE_REPAIR, CityIntent::TYPE_OVERHAUL => $this->cityRepair($state, $intent),
            CityIntent::TYPE_SHIELD => $this->cityShield($state, $intent),
            CityIntent::TYPE_REINFORCE => $this->cityReinforce($state, $intent),
            default => null,
        };

        if ($intent->type === CityIntent::TYPE_OVERHAUL) {
            $state->phase = 'standby';
            $state->flags['overhaul_interrupt_elements'] = [];

            $this->record($state, 'phase_change', BattleEvent::CITY, null, 'overhaul_completed',
                ['phase' => 'overhaul'],
                [],
                ['phase' => 'standby'],
                'cue.phase.overhaul_completed',
            );
        }

        $this->maybeStartOverhaul($state, $level);
    }

    private function cityRepair(BattleState $state, CityIntent $intent): void
    {
        $before = $state->coreResilience;
        $state->coreResilience = min(100, $before + $intent->magnitude);

        $this->record($state, 'city_repair', BattleEvent::CITY, $intent->element->value, 'scheduled_repair',
            ['core_resilience' => $before],
            ['core_resilience' => $state->coreResilience - $before],
            ['core_resilience' => $state->coreResilience],
            'cue.city.repair',
        );
    }

    /**
     * 城市同時只維持一道護盾，新的取代舊的。允許無限堆疊會讓吸收量滾雪球，
     * 玩家再怎麼打都進不了核心，那不是難度而是無解。
     */
    private function cityShield(BattleState $state, CityIntent $intent): void
    {
        $before = $state->totalShield();
        $state->shields = [[
            'element' => $intent->element->value,
            'amount' => $intent->magnitude,
            'expires_on_turn' => $state->turn + 2,
        ]];

        $this->record($state, 'city_shield', BattleEvent::CITY, $intent->element->value, 'scheduled_shield',
            ['shield' => $before],
            ['shield' => $intent->magnitude, 'expires_on_turn' => $state->turn + 2],
            ['shield' => $state->totalShield()],
            'cue.city.shield',
        );
    }

    private function cityReinforce(BattleState $state, CityIntent $intent): void
    {
        $element = $intent->element;
        $before = $state->defense($element);
        $after = min(100, $before + $intent->magnitude);
        $state->defenses[$element->value] = $after;

        // 回補到門檻以上，這條防線才重新可以觸發破綻。
        if ($after >= $this->config['breach']['rearm_defense']) {
            unset($state->breachedElements[$element->value]);
        }

        $this->record($state, 'city_reinforce', BattleEvent::CITY, $element->value, 'scheduled_reinforce',
            ['defense' => $before],
            ['defense' => $after - $before],
            ['defense' => $after],
            'cue.city.reinforce',
        );
    }

    /**
     * 蓄夜使徒：核心首次降到門檻以下啟動重整倒數。
     */
    private function maybeStartOverhaul(BattleState $state, LevelDefinition $level): void
    {
        if ($level->overhaul === null || ($state->flags['overhaul_started'] ?? false)) {
            return;
        }

        if ($state->coreResilience > $level->overhaul['trigger_core']) {
            return;
        }

        $state->flags['overhaul_started'] = true;
        $state->flags['overhaul_interrupt_elements'] = [];
        $state->flags['overhaul_due_turn'] = $state->turn + $level->overhaul['countdown_turns'];
        $state->phase = 'overhaul';

        $this->record($state, 'phase_change', BattleEvent::CITY, null, 'overhaul_started',
            ['phase' => 'standby', 'core_resilience' => $state->coreResilience],
            ['countdown_turns' => $level->overhaul['countdown_turns']],
            ['phase' => 'overhaul', 'due_turn' => $state->flags['overhaul_due_turn']],
            'cue.phase.overhaul_started',
        );
    }

    private function expireEffects(BattleState $state): void
    {
        $before = $state->totalShield();

        $state->shields = array_values(array_filter(
            $state->shields,
            static fn (array $shield): bool => $shield['expires_on_turn'] > $state->turn,
        ));

        if ($state->totalShield() !== $before) {
            $this->record($state, 'shield_expired', BattleEvent::CITY, null, 'shield_expired',
                ['shield' => $before],
                ['shield' => $state->totalShield() - $before],
                ['shield' => $state->totalShield()],
                'cue.shield.expire',
            );
        }
    }

    private function advanceTurn(BattleState $state, LevelDefinition $level): void
    {
        $state->turn++;
        $state->version++;

        $maliceBefore = $state->malice;
        $state->malice = min($state->maliceCap, $state->malice + $this->config['initial']['malice_regen']);

        $state->intent = $this->nextIntent($state, $level);

        $this->record($state, 'turn_advanced', BattleEvent::CITY, null, 'turn_start',
            ['malice' => $maliceBefore, 'turn' => $state->turn - 1],
            ['malice' => $state->malice - $maliceBefore],
            [
                'malice' => $state->malice,
                'turn' => $state->turn,
                'intent' => $state->intent?->toArray(),
                'phase' => $state->phase,
            ],
            'cue.turn.advance',
        );
    }

    private function nextIntent(BattleState $state, LevelDefinition $level): CityIntent
    {
        $dueTurn = $state->flags['overhaul_due_turn'] ?? null;

        if ($state->phase === 'overhaul' && $level->overhaul !== null && $dueTurn !== null) {
            // 重整倒數期間每回合都給可打斷的預告，玩家看得到剩幾回合。
            $element = $state->turn >= $dueTurn ? Element::Water : Element::Land;

            return new CityIntent(
                type: CityIntent::TYPE_OVERHAUL,
                element: $element,
                magnitude: $level->overhaul['repair_magnitude'],
                interruptible: true,
                scheduledTurn: $state->turn,
                description: sprintf(
                    '第 %d 回合結束時：重整倒數（第 %d／%d 回合），完成後回復核心韌性 %d；需要 %d 次不同系擾序才會中止',
                    $state->turn,
                    $state->turn - ($dueTurn - $level->overhaul['countdown_turns']),
                    $level->overhaul['countdown_turns'],
                    $level->overhaul['repair_magnitude'],
                    $level->overhaul['required_interrupts'],
                ),
            );
        }

        return $level->intentForTurn($state->turn);
    }

    private function finish(BattleState $state, Outcome $outcome, string $reasonCode): void
    {
        $state->outcome = $outcome;
        $state->version++;
        $state->intent = null;

        $this->record($state, 'outcome', BattleEvent::CITY, null, $reasonCode,
            ['outcome' => Outcome::InProgress->value],
            ['turn' => $state->turn],
            ['outcome' => $outcome->value, 'core_resilience' => $state->coreResilience],
            $outcome === Outcome::PlayerVictory ? 'cue.outcome.player_victory' : 'cue.outcome.city_held',
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $delta
     * @param  array<string, mixed>  $after
     */
    private function record(
        BattleState $state,
        string $type,
        string $actor,
        ?string $target,
        string $reasonCode,
        array $before,
        array $delta,
        array $after,
        string $cueId,
    ): void {
        $this->events[] = new BattleEvent(
            sequence: ++$this->sequence,
            turn: $this->actionTurn,
            type: $type,
            actor: $actor,
            target: $target,
            reasonCode: $reasonCode,
            before: $before,
            delta: $delta,
            after: $after,
            cueId: $cueId,
        );
    }
}
