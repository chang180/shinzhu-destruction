<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\LevelDefinition;
use Random\Randomizer;

/**
 * 規劃策略，但在一個可重現的關鍵窗口改出次佳合法牌，用來量測關卡的恢復空間（P10 §3.2）。
 *
 * 關鍵窗口只看玩家畫面上的資訊：第 2 回合起第一個「可打斷」的預告回合；
 * 打到一半（回合數過半）都沒遇到，就在過半那一回合失誤。每局只失誤一次。
 *
 * 策略物件會在不同 seed 之間重用，所以用「回合沒有前進」偵測新的一局並重置。
 */
class PlannerOneMistakeStrategy extends PlannerStrategy
{
    private int $lastTurn = 0;

    private bool $mistakeMade = false;

    public function name(): string
    {
        return 'planner-one-mistake';
    }

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        if ($state->turn <= $this->lastTurn) {
            $this->mistakeMade = false;
        }

        $this->lastTurn = $state->turn;

        if ($this->mistakeMade || ! $this->isKeyWindow($state)) {
            return parent::choose($state, $engine, $level, $rng);
        }

        $ranked = $this->ranked($state, $engine, $level);

        if (count($ranked) < 2) {
            return parent::choose($state, $engine, $level, $rng);
        }

        $this->mistakeMade = true;
        $action = $ranked[1][0];
        $action['keep'] = $this->keep($state, $action);

        return $action;
    }

    private function isKeyWindow(BattleState $state): bool
    {
        if ($state->turn < 2) {
            return false;
        }

        return ($state->intent?->interruptible ?? false)
            || $state->turn >= (int) ceil($state->maxTurns / 2);
    }
}
