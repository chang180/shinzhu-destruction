<?php

namespace App\Domain\Game\Exceptions;

use InvalidArgumentException;

/**
 * 關卡設定不合法。在載入 config/game.php 時就丟出，讓錯誤的幕次設定無法進入任何一局。
 */
class InvalidLevelConfigException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $levelId,
        string $message,
    ) {
        parent::__construct("[{$levelId}] {$message}");
    }
}
