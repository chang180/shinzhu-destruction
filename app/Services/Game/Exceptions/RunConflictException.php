<?php

namespace App\Services\Game\Exceptions;

use RuntimeException;

/**
 * 需要客戶端重新取得現況再決策的衝突，對應 HTTP 409。
 */
class RunConflictException extends RuntimeException
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

    public static function staleVersion(int $expected, int $current): self
    {
        return new self('stale_version', '局面版本已經前進，請重新取得現況再決策', [
            'expected_version' => $expected,
            'current_version' => $current,
        ]);
    }

    public static function actionIdReused(string $actionId): self
    {
        return new self('action_id_reused', '同一個 action_id 帶了不同的內容', [
            'action_id' => $actionId,
        ]);
    }
}
