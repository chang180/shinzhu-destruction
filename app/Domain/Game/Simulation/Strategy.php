<?php

namespace App\Domain\Game\Simulation;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\LevelDefinition;
use Random\Randomizer;

/**
 * 一個自動策略。給定局面挑一個合法行動；回傳 null 代表投降（實務上不會發生，
 * 蓄勢永遠是合法的）。
 *
 * 策略拿到的資訊必須和玩家在畫面上看得到的一樣：局面、預告、合法行動清單。
 * 不能偷看關卡設定表或未來回合。
 */
interface Strategy
{
    public function name(): string;

    /**
     * @return array{skill_id: string, target: string|null}|null
     */
    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array;
}
