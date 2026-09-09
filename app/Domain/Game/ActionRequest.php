<?php

namespace App\Domain\Game;

/**
 * 一次玩家行動的輸入。action_id 由客戶端產生並用於去重：
 * 同一 (run_id, action_id) 帶相同 payload 必須回傳原結果。
 *
 * `deadlineAt` 是伺服器在揭牌時決定的截止時間，不是客戶端送來的，因此
 * 不列入指紋——否則同一次揭牌重送會因為時間不同被判成「不同 payload」。
 */
final readonly class ActionRequest
{
    /** @var list<string> 出牌時要留到下回合的手牌（不含打出的那張） */
    public array $keep;

    /**
     * @param  list<string>  $keep
     */
    public function __construct(
        public string $actionId,
        public int $expectedVersion,
        public ActionType $type,
        public ?string $cardId = null,
        public ?string $fixedSkillId = null,
        array $keep = [],
        public ?string $deadlineAt = null,
    ) {
        // 留牌是一個集合，不是順序。正規化後同一組留牌不論送出順序都算同一次提交，
        // 重送才不會因為陣列順序不同被誤判成「同 action_id 不同 payload」。
        $keep = array_values(array_unique($keep));
        sort($keep);
        $this->keep = $keep;
    }

    /**
     * 逾時收斂：客戶端送來的出牌／換牌在截止之後才抵達，改判為逾時結算。
     * 保留原 action_id 與原指紋，所以重送同一筆仍然拿到同一個結果，
     * 不會變成「先逾時再補打一張」的兩次結算。
     */
    public function asTimeout(): self
    {
        return new self(
            actionId: $this->actionId,
            expectedVersion: $this->expectedVersion,
            type: ActionType::Timeout,
            cardId: $this->cardId,
            fixedSkillId: $this->fixedSkillId,
            keep: $this->keep,
        );
    }

    public function withDeadline(?string $deadlineAt): self
    {
        return new self(
            actionId: $this->actionId,
            expectedVersion: $this->expectedVersion,
            type: $this->type,
            cardId: $this->cardId,
            fixedSkillId: $this->fixedSkillId,
            keep: $this->keep,
            deadlineAt: $deadlineAt,
        );
    }

    /**
     * 去重比對用的 payload 指紋，只含客戶端能決定的欄位。
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'expected_version' => $this->expectedVersion,
            'type' => $this->type->value,
            'card_id' => $this->cardId,
            'fixed' => $this->fixedSkillId,
            'keep' => $this->keep,
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
            'type' => $this->type->value,
            'card_id' => $this->cardId,
            'fixed' => $this->fixedSkillId,
            'keep' => $this->keep,
            'deadline_at' => $this->deadlineAt,
        ];
    }
}
