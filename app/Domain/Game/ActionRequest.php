<?php

namespace App\Domain\Game;

/**
 * 一次玩家行動的輸入。action_id 由客戶端產生並用於去重：
 * 同一 (run_id, action_id) 帶相同 payload 重送必須回傳原結果。
 */
final readonly class ActionRequest
{
    public function __construct(
        public string $actionId,
        public int $expectedVersion,
        public string $skillId,
        public ?Element $target,
    ) {}

    /**
     * 去重比對用的 payload 指紋，不含 action_id 本身。
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'expected_version' => $this->expectedVersion,
            'skill_id' => $this->skillId,
            'target' => $this->target?->value,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'expected_version' => $this->expectedVersion,
            'skill_id' => $this->skillId,
            'target' => $this->target?->value,
        ];
    }
}
