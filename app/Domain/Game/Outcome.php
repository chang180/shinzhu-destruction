<?php

namespace App\Domain\Game;

enum Outcome: string
{
    case InProgress = 'in_progress';

    /** 城市核心韌性歸零＝玩家勝利。 */
    case PlayerVictory = 'player_victory';

    /** 回合耗盡仍有韌性＝城市守住＝玩家挑戰失敗。 */
    case CityHeld = 'city_held';

    public function isFinished(): bool
    {
        return $this !== self::InProgress;
    }
}
