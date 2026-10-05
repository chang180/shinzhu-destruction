<?php

namespace App\Domain\Game\Phases;

use App\Domain\Game\BattleState;
use App\Domain\Game\LevelDefinition;

/**
 * 決定局面目前在哪一幕、回合邊界是否該進入下一幕。
 *
 * 幕次只會照設定順序往前一步：每個回合邊界最多前進一幕，不回退、不跳到任意 ID。
 * 舊存檔沒有幕次欄位（null）時視為第一幕。
 */
final class LevelPhaseResolver
{
    public static function current(LevelDefinition $level, BattleState $state): LevelPhaseDefinition
    {
        return $level->levelPhases[self::index($level, $state)];
    }

    public static function index(LevelDefinition $level, BattleState $state): int
    {
        if ($state->levelPhaseId === null) {
            return 0;
        }

        foreach ($level->levelPhases as $index => $phase) {
            if ($phase->id === $state->levelPhaseId) {
                return $index;
            }
        }

        return 0;
    }

    public static function next(LevelDefinition $level, BattleState $state): ?LevelPhaseDefinition
    {
        return $level->levelPhases[self::index($level, $state) + 1] ?? null;
    }

    /**
     * 回合邊界要進入的下一幕；條件不成立或已是最後一幕回 null。
     * 呼叫時 $state->turn 仍是剛結束的回合，turn_gte 比對的是即將開始的回合。
     */
    public static function transition(LevelDefinition $level, BattleState $state): ?LevelPhaseDefinition
    {
        $next = self::next($level, $state);

        if ($next === null) {
            return null;
        }

        return $next->startsWhen->isSatisfied($state->turn + 1, $state->coreResilience, $state->flags) ? $next : null;
    }
}
