<?php

namespace App\Domain\Game;

use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Cards\Deck;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\Scenario\ScenarioModifiers;

/**
 * 唯一的規則計算來源。
 *
 * 完全確定性：同一組（關卡、情境修正、seed、行動序列）永遠得到同一串事件與結局。
 * 城市行為由關卡預告表決定，沒有亂數；唯一的亂數是牌序，而牌序完全由
 * (seed, 第幾次洗牌) 決定並保存在局面裡，所以重播不需要保存亂數器狀態。
 *
 * 一個回合分成兩步：`reveal` 揭示手牌並開啟決策窗口，`play` 或 `timeout`
 * 結束回合。`swap` 在窗口內可用一次，改變手牌但不讓城市行動。
 *
 * 出牌的結算順序固定為 GAME-DESIGN §3.3：
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
        private readonly CardCatalog $cards,
        private readonly array $config,
    ) {}

    public function rulesVersion(): string
    {
        return $this->config['rules_version'];
    }

    /**
     * 開局。牌組在這裡展開成實體牌並依 seed 洗好，手牌要等玩家揭牌才發。
     */
    public function start(LevelDefinition $level, int $seed): BattleState
    {
        $initial = $this->config['initial'];
        $hand = $this->config['hand'];
        $deck = Deck::expand($level->deck);

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
            deck: $deck,
            hand: [],
            drawPile: Deck::openingOrder($deck, $this->cards, $seed),
            discardPile: [],
            handSize: $hand['size'],
            maxKeep: $hand['max_keep'],
            turnPhase: BattleState::PHASE_AWAITING_REVEAL,
            deadlineAt: null,
            swapUsed: false,
            shuffleCount: 0,
            timeouts: 0,
            deckSeed: $seed,
        );
    }

    public function apply(
        BattleState $state,
        ActionRequest $action,
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
    ): TurnResult {
        if ($state->outcome->isFinished()) {
            throw InvalidActionException::runFinished();
        }

        return match ($action->type) {
            ActionType::Reveal => $this->applyReveal($state, $action),
            ActionType::Swap => $this->applySwap($state, $action),
            ActionType::Timeout => $this->applyTimeout($state, $level),
            ActionType::Play => $this->applyPlay($state, $action, $level, $modifiers),
        };
    }

    /**
     * 揭牌：補滿手牌並開啟決策窗口。城市不動，回合不推進。
     */
    private function applyReveal(BattleState $state, ActionRequest $action): TurnResult
    {
        if ($state->turnPhase !== BattleState::PHASE_AWAITING_REVEAL) {
            throw InvalidActionException::alreadyRevealed();
        }

        $this->beginEvents($state);
        $next = $state->copy();

        $kept = $next->hand;
        $drawn = $this->draw($next, $next->handSize - count($next->hand));

        $next->turnPhase = BattleState::PHASE_DECISION;
        $next->deadlineAt = $action->deadlineAt;
        $next->swapUsed = false;
        $next->version++;

        $this->record($next, 'hand_revealed', BattleEvent::PLAYER, null, 'decision_window_opened',
            ['kept' => $kept],
            ['drawn' => $drawn],
            ['hand' => $next->hand, 'deadline_at' => $next->deadlineAt, 'draw_pile_count' => count($next->drawPile)],
            'cue.hand.revealed',
        );

        return new TurnResult($next, $this->events);
    }

    /**
     * 換牌：每回合一次，免費，不推進城市回合也不重設倒數。
     *
     * 換掉的牌先離開可抽集合再進棄牌堆，所以不會立刻把同一張實體牌換回來
     * （P04-REVISION-PLAN §4.4）。
     */
    private function applySwap(BattleState $state, ActionRequest $action): TurnResult
    {
        if ($state->turnPhase !== BattleState::PHASE_DECISION) {
            throw InvalidActionException::handNotRevealed();
        }

        if ($state->swapUsed) {
            throw InvalidActionException::swapAlreadyUsed();
        }

        $cardId = $action->cardId;

        if ($cardId === null || ! $state->inHand($cardId)) {
            throw InvalidActionException::cardNotInHand((string) $cardId);
        }

        if ($state->drawPile === [] && $state->discardPile === []) {
            throw InvalidActionException::nothingToSwap();
        }

        $this->beginEvents($state);
        $next = $state->copy();

        $next->hand = array_values(array_filter($next->hand, static fn (string $id): bool => $id !== $cardId));
        $drawn = $this->draw($next, 1);
        $next->discardPile[] = $cardId;
        $next->swapUsed = true;
        $next->version++;

        $this->record($next, 'card_swapped', BattleEvent::PLAYER, null, 'free_swap_used',
            ['card' => $cardId],
            ['drawn' => $drawn],
            ['hand' => $next->hand, 'draw_pile_count' => count($next->drawPile)],
            'cue.hand.swapped',
        );

        return new TurnResult($next, $this->events);
    }

    /**
     * 逾時：錯失行動。不施放任何牌、不扣惡意，城市照預告行動。
     *
     * 連攜鏈與破綻窗口按錯失行動規則消耗——窗口是「下一個行動」用掉的，
     * 錯過那個行動就等於錯過窗口（P04-REVISION-PLAN §5）。
     */
    private function applyTimeout(BattleState $state, LevelDefinition $level): TurnResult
    {
        if ($state->turnPhase !== BattleState::PHASE_DECISION) {
            throw InvalidActionException::handNotRevealed();
        }

        $this->beginEvents($state);
        $next = $state->copy();
        $next->timeouts++;

        $this->record($next, 'action_missed', BattleEvent::PLAYER, null, 'decision_window_expired',
            ['hand' => $next->hand, 'breach_available' => $next->breachAvailable, 'combo_chain' => $next->comboChain],
            ['timeouts' => 1],
            ['malice' => $next->malice],
            'cue.turn.missed',
        );

        if ($next->breachAvailable) {
            $next->breachAvailable = false;

            $this->record($next, 'breach_consumed', BattleEvent::CITY, null, 'missed_action_wasted_breach',
                ['breach_available' => true],
                [],
                ['breach_available' => false],
                'cue.breach.wasted',
            );
        }

        $next->comboChain = [];
        $this->discardHand($next);

        $this->resolveCityResponse($next, $level, false);
        $this->expireEffects($next);

        if ($next->turn >= $next->maxTurns) {
            $this->finish($next, Outcome::CityHeld, 'turns_exhausted');

            return new TurnResult($next, $this->events);
        }

        $this->advanceTurn($next, $level);

        return new TurnResult($next, $this->events);
    }

    private function applyPlay(
        BattleState $state,
        ActionRequest $action,
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
    ): TurnResult {
        if ($state->turnPhase !== BattleState::PHASE_DECISION) {
            throw InvalidActionException::handNotRevealed();
        }

        $skill = $this->resolvePlayedSkill($state, $action);
        $this->validateKeep($state, $action);
        $this->validateSkill($state, $skill);

        $this->beginEvents($state);
        $next = $state->copy();

        $this->spendMalice($next, $skill);

        if ($skill->kind === SkillKind::Gather) {
            $this->resolveGather($next, $skill);
        } else {
            $this->resolveOffensive($next, $skill, $level, $modifiers);
        }

        $interrupted = $this->resolveInterrupt($next, $skill, $level);
        $this->settleHand($next, $action);

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
     * 逾時不在清單裡：那不是玩家的選項，是伺服器對截止時間的判定。
     *
     * @return list<array{type: string, card_id: string|null, fixed: string|null, skill_id: string|null, target: string|null, reason: string|null}>
     */
    public function availableActions(BattleState $state): array
    {
        if ($state->outcome->isFinished()) {
            return [];
        }

        if ($state->turnPhase === BattleState::PHASE_AWAITING_REVEAL) {
            return [$this->action('reveal', null, null, null, null, null)];
        }

        $actions = [];

        foreach ($state->hand as $instanceId) {
            $type = $state->cardType($instanceId);
            $skill = $type === null ? null : $this->cards->skillFor($type);

            $actions[] = $this->action(
                'play',
                $instanceId,
                null,
                $skill?->id,
                $skill?->element?->value,
                $skill === null ? 'card_not_in_hand' : $this->skillRejection($state, $skill),
            );
        }

        foreach ($this->config['fixed_actions'] as $skillId) {
            $skill = $this->skills->get($skillId);

            $actions[] = $this->action('play', null, $skillId, $skillId, null, $this->skillRejection($state, $skill));
        }

        $swapReason = match (true) {
            $state->swapUsed => 'swap_already_used',
            $state->drawPile === [] && $state->discardPile === [] => 'nothing_to_swap',
            default => null,
        };

        foreach ($state->hand as $instanceId) {
            $actions[] = $this->action('swap', $instanceId, null, null, null, $swapReason);
        }

        return $actions;
    }

    /**
     * @return list<array{type: string, card_id: string|null, fixed: string|null, skill_id: string|null, target: string|null, reason: string|null}>
     */
    public function legalActions(BattleState $state): array
    {
        return array_values(array_filter(
            $this->availableActions($state),
            static fn (array $action): bool => $action['reason'] === null,
        ));
    }

    /**
     * @return array{type: string, card_id: string|null, fixed: string|null, skill_id: string|null, target: string|null, reason: string|null}
     */
    private function action(
        string $type,
        ?string $cardId,
        ?string $fixed,
        ?string $skillId,
        ?string $target,
        ?string $reason,
    ): array {
        return [
            'type' => $type,
            'card_id' => $cardId,
            'fixed' => $fixed,
            'skill_id' => $skillId,
            'target' => $target,
            'reason' => $reason,
        ];
    }

    /**
     * 出牌指向的技能：手牌上的一張實體牌，或手牌旁的固定行動。
     */
    private function resolvePlayedSkill(BattleState $state, ActionRequest $action): Skill
    {
        if ($action->cardId !== null) {
            if (! $state->inHand($action->cardId)) {
                throw InvalidActionException::cardNotInHand($action->cardId);
            }

            $type = $state->cardType($action->cardId);

            if ($type === null || ! $this->cards->has($type)) {
                throw InvalidActionException::cardNotInHand($action->cardId);
            }

            return $this->cards->skillFor($type);
        }

        if ($action->fixedSkillId === null) {
            throw InvalidActionException::playTargetRequired();
        }

        if (! in_array($action->fixedSkillId, $this->config['fixed_actions'], true)) {
            throw InvalidActionException::fixedActionNotAllowed($action->fixedSkillId);
        }

        return $this->skills->get($action->fixedSkillId);
    }

    private function validateKeep(BattleState $state, ActionRequest $action): void
    {
        if (count($action->keep) > $state->maxKeep) {
            throw InvalidActionException::keepLimitExceeded($state->maxKeep, count($action->keep));
        }

        // 打出的那一張不能同時留下，所以它不在可留清單裡。
        $keepable = array_values(array_filter(
            $state->hand,
            static fn (string $id): bool => $id !== $action->cardId,
        ));

        $unknown = array_values(array_diff($action->keep, $keepable));

        if ($unknown !== []) {
            throw InvalidActionException::keepNotInHand($unknown);
        }
    }

    /**
     * 技能層級的驗證。只讀狀態，不改任何東西：任何一項不過就丟例外，
     * 回合與惡意都不消耗。
     */
    private function validateSkill(BattleState $state, Skill $skill): void
    {
        if (! $state->skillReady($skill->id)) {
            throw InvalidActionException::onCooldown($skill->id, $state->cooldowns[$skill->id]);
        }

        if ($state->malice < $skill->maliceCost) {
            throw InvalidActionException::notEnoughMalice($skill->maliceCost, $state->malice);
        }

        if ($skill->kind !== SkillKind::Ultimate) {
            return;
        }

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

    private function skillRejection(BattleState $state, Skill $skill): ?string
    {
        try {
            $this->validateSkill($state, $skill);

            return null;
        } catch (InvalidActionException $exception) {
            return $exception->reasonCode;
        }
    }

    /**
     * 出牌後整理手牌：打出的牌與未指定留下的牌都進棄牌堆，留牌繼續佔下回合手牌位置。
     */
    private function settleHand(BattleState $state, ActionRequest $action): void
    {
        $before = $state->hand;
        $discarded = [];

        foreach ($state->hand as $instanceId) {
            if (! in_array($instanceId, $action->keep, true)) {
                $discarded[] = $instanceId;
            }
        }

        $state->discardPile = array_merge($state->discardPile, $discarded);
        $state->hand = array_values($action->keep);

        $this->record($state, 'hand_settled', BattleEvent::PLAYER, null, 'played_and_discarded',
            ['hand' => $before],
            ['played' => $action->cardId ?? $action->fixedSkillId, 'discarded' => $discarded],
            ['kept' => $state->hand],
            'cue.hand.settled',
        );
    }

    private function discardHand(BattleState $state): void
    {
        if ($state->hand === []) {
            return;
        }

        $before = $state->hand;
        $state->discardPile = array_merge($state->discardPile, $state->hand);
        $state->hand = [];

        $this->record($state, 'hand_settled', BattleEvent::PLAYER, null, 'missed_action_discarded',
            ['hand' => $before],
            ['discarded' => $before],
            ['kept' => []],
            'cue.hand.settled',
        );
    }

    /**
     * 抽牌。抽牌堆用盡才把棄牌堆洗回來，洗牌次數加一，牌序仍然由 seed 決定。
     *
     * @return list<string>
     */
    private function draw(BattleState $state, int $count): array
    {
        $drawn = [];

        for ($i = 0; $i < $count; $i++) {
            if ($state->drawPile === []) {
                if ($state->discardPile === []) {
                    break;
                }

                $state->shuffleCount++;
                $state->drawPile = Deck::shuffle($state->discardPile, $state->deckSeed, $state->shuffleCount);
                $state->discardPile = [];
            }

            $drawn[] = array_shift($state->drawPile);
        }

        $state->hand = array_merge($state->hand, $drawn);

        return $drawn;
    }

    private function beginEvents(BattleState $state): void
    {
        $this->sequence = 0;
        $this->events = [];
        $this->actionTurn = $state->turn;
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

        // 下一回合先回到未揭牌：倒數在玩家按下「開始回合」之前不會跑，
        // 演出與閱讀預告的時間不算進 30 秒（P04-REVISION-PLAN §5）。
        $state->turnPhase = BattleState::PHASE_AWAITING_REVEAL;
        $state->deadlineAt = null;
        $state->swapUsed = false;

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
        $state->turnPhase = BattleState::PHASE_AWAITING_REVEAL;
        $state->deadlineAt = null;

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
