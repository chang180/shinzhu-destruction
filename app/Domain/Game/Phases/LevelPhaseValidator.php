<?php

namespace App\Domain\Game\Phases;

use App\Domain\Game\Exceptions\InvalidLevelConfigException;

/**
 * 一關幕次清單的結構檢查。
 *
 * 只能證明「設定本身沒有矛盾、每一幕在結構上有可能進入」。core_lte 與 flag_true
 * 是否真的會在某一局成立取決於玩家怎麼打，驗證器不宣稱任何一局一定會到達某一幕；
 * 執行時尚未到達的幕不是設定錯誤。
 */
final class LevelPhaseValidator
{
    /**
     * @param  list<LevelPhaseDefinition>  $phases
     * @param  list<string>  $settableFlags  這一關的機制實際會設定的旗標
     */
    public static function validate(string $levelId, int $maxTurns, array $phases, array $settableFlags): void
    {
        if ($phases === []) {
            throw new InvalidLevelConfigException('phase_missing_first', $levelId, '至少要有一幕');
        }

        $ids = array_map(static fn (LevelPhaseDefinition $phase): string => $phase->id, $phases);
        $duplicates = array_keys(array_filter(array_count_values($ids), static fn (int $count): bool => $count > 1));

        if ($duplicates !== []) {
            throw new InvalidLevelConfigException('phase_duplicate_id', $levelId, '重複的 phase id：'.implode(', ', $duplicates));
        }

        $first = $phases[0]->startsWhen;

        if ($first->type !== PhaseTrigger::TURN_GTE || $first->value !== 1) {
            throw new InvalidLevelConfigException('phase_first_not_initial', $levelId, "第一幕 {$phases[0]->id} 必須以 turn_gte 1 開始");
        }

        $previousThreshold = 1;

        foreach (array_slice($phases, 1) as $phase) {
            self::assertFlagsSettable($levelId, $phase, $phase->startsWhen, $settableFlags);

            if (! $phase->startsWhen->structurallyReachable($maxTurns, $settableFlags)) {
                throw new InvalidLevelConfigException('phase_unreachable', $levelId, "phase {$phase->id} 的條件在結構上不可能成立（{$phase->startsWhen->describe()}）");
            }

            $threshold = $phase->startsWhen->turnThreshold();

            if ($threshold !== null) {
                if ($threshold <= $previousThreshold) {
                    throw new InvalidLevelConfigException('phase_order_regression', $levelId, "phase {$phase->id} 的回合門檻 {$threshold} 不晚於前一幕的 {$previousThreshold}");
                }

                $previousThreshold = $threshold;
            }
        }
    }

    /**
     * @param  list<string>  $settableFlags
     */
    private static function assertFlagsSettable(string $levelId, LevelPhaseDefinition $phase, PhaseTrigger $trigger, array $settableFlags): void
    {
        if ($trigger->type === PhaseTrigger::FLAG_TRUE && ! in_array($trigger->flag, $settableFlags, true)) {
            throw new InvalidLevelConfigException('trigger_flag_not_settable', $levelId, "phase {$phase->id}: 這一關的機制不會設定旗標 {$trigger->flag}");
        }

        foreach ($trigger->children as $child) {
            self::assertFlagsSettable($levelId, $phase, $child, $settableFlags);
        }
    }
}
