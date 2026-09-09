<?php

namespace App\Domain\Game;

/**
 * 挑戰與練習用同一套牌組、同一套城市規則、同一個引擎；差別只有截止時間。
 * 兩者的解鎖與最佳表現分開記錄，練習不冒充限時通關。
 */
enum RunMode: string
{
    case Challenge = 'challenge';

    case Practice = 'practice';

    public function isTimed(): bool
    {
        return $this === self::Challenge;
    }

    public function label(): string
    {
        return match ($this) {
            self::Challenge => '限時挑戰',
            self::Practice => '不限時練習',
        };
    }
}
