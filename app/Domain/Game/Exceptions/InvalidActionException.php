<?php

namespace App\Domain\Game\Exceptions;

use RuntimeException;

/**
 * 非法行動。引擎在扣任何資源之前丟出，所以不合法的提交不會消耗回合或惡意
 * （驗收：非法／重複行動不多扣資源）。
 */
class InvalidActionException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function unknownSkill(string $skillId): self
    {
        return new self('unknown_skill', "未知的技能代碼：{$skillId}", ['skill_id' => $skillId]);
    }

    public static function targetRequired(string $skillId): self
    {
        return new self('target_required', "技能 {$skillId} 需要指定系別", ['skill_id' => $skillId]);
    }

    public static function targetNotAllowed(string $skillId): self
    {
        return new self('target_not_allowed', "技能 {$skillId} 不接受系別", ['skill_id' => $skillId]);
    }

    public static function runFinished(): self
    {
        return new self('run_finished', '這一局已經結束，不能再提交行動');
    }

    public static function onCooldown(string $skillId, int $readyOnTurn): self
    {
        return new self('on_cooldown', "技能 {$skillId} 要到第 {$readyOnTurn} 回合才可再用", [
            'skill_id' => $skillId,
            'ready_on_turn' => $readyOnTurn,
        ]);
    }

    public static function notEnoughMalice(int $required, int $available): self
    {
        return new self('not_enough_malice', "惡意不足：需要 {$required}，只有 {$available}", [
            'required' => $required,
            'available' => $available,
        ]);
    }

    /**
     * @param  array<string, int>  $missing
     */
    public static function sigilsNotReady(array $missing): self
    {
        return new self('sigils_not_ready', '終招印記不足', ['missing' => $missing]);
    }

    public static function handNotRevealed(): self
    {
        return new self('hand_not_revealed', '手牌尚未揭示，請先開始這一回合');
    }

    public static function alreadyRevealed(): self
    {
        return new self('already_revealed', '這一回合的手牌已經揭示');
    }

    public static function cardNotInHand(string $cardId): self
    {
        return new self('card_not_in_hand', '這張牌不在手牌裡', ['card_id' => $cardId]);
    }

    public static function swapAlreadyUsed(): self
    {
        return new self('swap_already_used', '這一回合的換牌已經用過了');
    }

    public static function nothingToSwap(): self
    {
        return new self('nothing_to_swap', '抽牌堆與棄牌堆都沒有牌可以換');
    }

    /**
     * @param  list<string>  $keep
     */
    public static function keepNotInHand(array $keep): self
    {
        return new self('keep_not_in_hand', '要留下的牌必須是本回合未打出的手牌', ['keep' => $keep]);
    }

    public static function keepLimitExceeded(int $limit, int $requested): self
    {
        return new self('keep_limit_exceeded', "最多只能留 {$limit} 張牌，這次指定了 {$requested} 張", [
            'limit' => $limit,
            'requested' => $requested,
        ]);
    }

    public static function fixedActionNotAllowed(string $skillId): self
    {
        return new self('fixed_action_not_allowed', "{$skillId} 不是手牌旁的固定行動", ['skill_id' => $skillId]);
    }

    public static function playTargetRequired(): self
    {
        return new self('play_target_required', '出牌必須指定一張手牌或一個固定行動');
    }

    public static function notTimedOut(): self
    {
        return new self('not_timed_out', '這一回合的決策窗口還沒有結束');
    }
}
