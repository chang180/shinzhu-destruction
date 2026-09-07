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
}
