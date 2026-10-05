<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\CityIntent;
use App\Domain\Game\Element;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Simulation\InstrumentedStrategy;
use App\Domain\Game\Skill;
use App\Domain\Game\SkillKind;
use Random\Randomizer;

/**
 * 規劃策略，但在本關核心機制上注入一次**明確可辨識的錯誤處理**，用來量測關卡的
 * 恢復空間（P10 §3.2）。
 *
 * P10-2.1 重新定義了「一次失誤」。P10-0.1～P10-2 的定義是「關鍵窗口裡第一個語義
 * 不同且 planner 分數嚴格較低的合法行動」，實測有約七成不是機制錯誤，只是比較弱的
 * 合法打法；noon-fold 甚至出現「最佳是直接輸出、注入的失誤卻是正確打斷水盾」這種
 * 反向標記。詳見 docs/phase-reports/P10-2.1.md。
 *
 * 現在只承認兩種失誤，兩者都只讀玩家當下看得見的資訊（預告、站著的護盾、手牌）：
 *
 * 1. `missed_interrupt`：預告是可打斷的修復／護盾／重整，手上有同系擾序，
 *    **而且 planner 的最佳打法就是去打斷**，卻改成不處理那個預告的合法行動。
 * 2. `walked_into_shield`：場上有同系護盾，**planner 的最佳打法繞開了它**，
 *    卻改成會被那道盾吸收的攻擊。
 *
 * 兩種都要求「planner 的最佳打法本身就是正確處理」。少了這個條件，另一條同樣合理
 * 的策略會被誤標成失誤——planner 的選擇本來就是一條合理策略。
 *
 * 從第一回合起逐回合檢查，第一個能構成明確機制錯誤的回合就注入，每局最多一次。
 * 整局都找不到就記 `no_eligible_mistake`，不硬塞任意次佳動作，也不為個別 seed 特判。
 */
class PlannerOneMistakeStrategy extends PlannerStrategy implements InstrumentedStrategy
{
    public const STATUS_INJECTED = 'injected';

    public const STATUS_NO_ELIGIBLE = 'no_eligible_mistake';

    /** 可打斷的重要窗口沒有被處理。 */
    public const KIND_MISSED_INTERRUPT = 'missed_interrupt';

    /** 撞上可以繞開的同系護盾。 */
    public const KIND_WALKED_INTO_SHIELD = 'walked_into_shield';

    /**
     * 只有這些預告值得花一回合打斷；`reinforce` 只是補防線，不是第 1、2 關要教的機制。
     */
    private const INTERRUPT_WORTHY = [
        CityIntent::TYPE_REPAIR,
        CityIntent::TYPE_SHIELD,
        CityIntent::TYPE_OVERHAUL,
    ];

    /**
     * @var array<string, mixed>
     */
    private array $report = [];

    public function name(): string
    {
        return 'planner-one-mistake';
    }

    public function beginGame(): void
    {
        $this->report = [
            'status' => self::STATUS_NO_ELIGIBLE,
            'turn' => null,
            'mistake_kind' => null,
            'best' => null,
            'mistake' => null,
            'score_delta' => null,
            'context' => null,
        ];
    }

    public function gameReport(): array
    {
        return $this->report;
    }

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        if ($this->report === []) {
            $this->beginGame();
        }

        if ($this->report['status'] === self::STATUS_INJECTED) {
            return parent::choose($state, $engine, $level, $rng);
        }

        $ranked = $this->ranked($state, $engine, $level);

        if ($ranked === []) {
            return parent::choose($state, $engine, $level, $rng);
        }

        $candidate = $this->mechanicMistake($state, $ranked);

        if ($candidate === null) {
            /*
             * 這一回合沒有明確的機制錯誤可以注入；維持 planner 打法，往後面的回合繼續找。
             * 直接用已經算好的排名，不再呼叫 parent::choose()——那會把整個前瞻重算一次，
             * 而本策略現在每回合都要排名，重算等於把成本翻倍。
             */
            return $this->plannerPick($state, $ranked);
        }

        [$best, $bestScore] = $ranked[0];
        [$action, $score, $kind, $context] = $candidate;

        $this->report = [
            'status' => self::STATUS_INJECTED,
            'turn' => $state->turn,
            'mistake_kind' => $kind,
            'best' => self::describe($best, $bestScore),
            'mistake' => self::describe($action, $score),
            'score_delta' => self::number($bestScore - $score),
            'context' => $context,
        ];

        $action['keep'] = $this->keep($state, $action);

        return $action;
    }

    /**
     * 和 planner 完全相同的選擇：`ranked()` 的第一名就是 `LookaheadStrategy::choose()`
     * 會挑的那一個（分數最高，同分取合法清單裡較早的）。
     *
     * @param  list<array{0: array<string, mixed>, 1: float}>  $ranked
     * @return array<string, mixed>
     */
    private function plannerPick(BattleState $state, array $ranked): array
    {
        $best = $ranked[0][0];
        $best['keep'] = $this->keep($state, $best);

        return $best;
    }

    /**
     * 這一回合能不能構成明確的機制錯誤。
     *
     * @param  list<array{0: array<string, mixed>, 1: float}>  $ranked
     * @return array{0: array<string, mixed>, 1: float, 2: string, 3: array<string, mixed>}|null
     */
    private function mechanicMistake(BattleState $state, array $ranked): ?array
    {
        [$best, $bestScore] = $ranked[0];
        $alternatives = array_slice($ranked, 1);

        $missed = $this->missedInterrupt($state, $best, $bestScore, $alternatives);

        if ($missed !== null) {
            return $missed;
        }

        return $this->walkedIntoShield($state, $best, $bestScore, $alternatives);
    }

    /**
     * 可打斷的重要窗口：planner 要打斷，我們偏偏不處理它。
     *
     * @param  array<string, mixed>  $best
     * @param  list<array{0: array<string, mixed>, 1: float}>  $alternatives
     * @return array{0: array<string, mixed>, 1: float, 2: string, 3: array<string, mixed>}|null
     */
    private function missedInterrupt(BattleState $state, array $best, float $bestScore, array $alternatives): ?array
    {
        $intent = $state->intent;

        if ($intent === null
            || ! $intent->interruptible
            || ! in_array($intent->type, self::INTERRUPT_WORTHY, true)
            || ! $this->interrupts($state, $best, $intent)) {
            return null;
        }

        foreach ($alternatives as [$action, $score]) {
            if ($score < $bestScore
                && ! $this->interrupts($state, $action, $intent)
                && self::signature($action) !== self::signature($best)) {
                return [$action, $score, self::KIND_MISSED_INTERRUPT, [
                    'intent_type' => $intent->type,
                    'intent_element' => $intent->element->value,
                    'intent_magnitude' => $intent->magnitude,
                ]];
            }
        }

        return null;
    }

    /**
     * 同系護盾窗口：planner 繞開了盾，我們偏偏撞上去讓傷害被吸收。
     *
     * @param  array<string, mixed>  $best
     * @param  list<array{0: array<string, mixed>, 1: float}>  $alternatives
     * @return array{0: array<string, mixed>, 1: float, 2: string, 3: array<string, mixed>}|null
     */
    private function walkedIntoShield(BattleState $state, array $best, float $bestScore, array $alternatives): ?array
    {
        $shielded = $this->shieldedElements($state);

        if ($shielded === [] || $this->absorbed($state, $best, $shielded)) {
            return null;
        }

        foreach ($alternatives as [$action, $score]) {
            if ($score < $bestScore
                && $this->absorbed($state, $action, $shielded)
                && self::signature($action) !== self::signature($best)) {
                return [$action, $score, self::KIND_WALKED_INTO_SHIELD, [
                    'shielded_elements' => array_keys($shielded),
                    'shield_amount' => array_sum($shielded),
                ]];
            }
        }

        return null;
    }

    /**
     * 站著的同系護盾：系別 => 總量。引擎已經在回合推進時清掉過期的盾。
     *
     * @return array<string, int>
     */
    private function shieldedElements(BattleState $state): array
    {
        $shielded = [];

        foreach ($state->shields as $shield) {
            if ($shield['amount'] > 0) {
                $shielded[$shield['element']] = ($shielded[$shield['element']] ?? 0) + $shield['amount'];
            }
        }

        return $shielded;
    }

    /**
     * 這個行動會不會正確打斷這個預告：同系擾序。
     *
     * @param  array<string, mixed>  $action
     */
    private function interrupts(BattleState $state, array $action, CityIntent $intent): bool
    {
        return $this->kindOf($state, $action) === SkillKind::Disrupt
            && $this->elementOf($state, $action) === $intent->element;
    }

    /**
     * 這個行動的傷害會不會被站著的盾吸收。
     *
     * 蓄勢沒有傷害，不算撞盾；終招沒有系別，任何一道盾都吃得到（3.0.0 規則）。
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, int>  $shielded
     */
    private function absorbed(BattleState $state, array $action, array $shielded): bool
    {
        $kind = $this->kindOf($state, $action);

        if ($kind === SkillKind::Gather) {
            return false;
        }

        if ($kind === SkillKind::Ultimate) {
            return $shielded !== [];
        }

        $element = $this->elementOf($state, $action);

        return $element !== null && array_key_exists($element->value, $shielded);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function elementOf(BattleState $state, array $action): ?Element
    {
        return $this->skillOf($state, $action)?->element;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function kindOf(BattleState $state, array $action): ?SkillKind
    {
        $skill = $this->skillOf($state, $action);

        if ($skill !== null) {
            return $skill->kind;
        }

        $fixed = $action['fixed'] ?? null;

        return $fixed === null ? null : SkillKind::tryFrom($fixed);
    }

    /**
     * `card_id` 是實體牌 ID，牌型要先經局面查：牌型決定技能，技能決定系別與類型。
     *
     * @param  array<string, mixed>  $action
     */
    private function skillOf(BattleState $state, array $action): ?Skill
    {
        $cardId = $action['card_id'] ?? null;
        $type = $cardId === null ? null : $state->cardType($cardId);

        return $type === null ? null : $this->cards->skillFor($type);
    }

    /**
     * 行動的語義簽章：同招、同系、同固定行動視為同一個決策，不看實體牌 ID。
     *
     * @param  array<string, mixed>  $action
     */
    public static function signature(array $action): string
    {
        return implode('|', [
            $action['type'] ?? '',
            $action['skill_id'] ?? '',
            $action['target'] ?? '',
            $action['fixed'] ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private static function describe(array $action, float $score): array
    {
        return [
            'type' => $action['type'],
            'skill_id' => $action['skill_id'],
            'target' => $action['target'],
            'fixed' => $action['fixed'],
            'card_id' => $action['card_id'],
            'score' => self::number($score),
        ];
    }

    /**
     * JSON 不能表示無限大；勝利一手的 INF 改寫成字串，其餘四捨五入到 4 位。
     */
    private static function number(float $value): float|string
    {
        return is_infinite($value) ? ($value > 0 ? 'INF' : '-INF') : round($value, 4);
    }
}
