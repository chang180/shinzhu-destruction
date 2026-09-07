<?php

namespace App\Services\OpenData\Snapshot;

/**
 * 快照品質。UI 必須逐來源顯示，不可因其中一個來源新鮮就把整局標成即時。
 */
enum SnapshotQuality: string
{
    /** 來自上游、觀測／涵蓋期間仍在門檻內。 */
    case Fresh = 'fresh';

    /** 來自上游、解析成功，但期間已超過門檻。 */
    case Stale = 'stale';

    /** 版本化示範情境，不是實際觀測值。 */
    case Demo = 'demo';

    /** 沒有任何可用資料。 */
    case Unavailable = 'unavailable';

    public function isRealData(): bool
    {
        return $this === self::Fresh || $this === self::Stale;
    }

    public function label(): string
    {
        return match ($this) {
            self::Fresh => '最新資料',
            self::Stale => '過期資料',
            self::Demo => '示範情境',
            self::Unavailable => '無可用資料',
        };
    }
}
