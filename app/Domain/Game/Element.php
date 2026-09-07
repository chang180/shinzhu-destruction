<?php

namespace App\Domain\Game;

/**
 * 三系。水／熱／土地與 SDGs 的連結寫在資料說明，不影響規則計算。
 */
enum Element: string
{
    case Water = 'water';
    case Heat = 'heat';
    case Land = 'land';

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::Water, self::Heat, self::Land];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $element): string => $element->value, self::all());
    }

    public function label(): string
    {
        return match ($this) {
            self::Water => '水',
            self::Heat => '熱',
            self::Land => '土地',
        };
    }
}
