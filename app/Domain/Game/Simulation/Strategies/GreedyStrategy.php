<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleState;
use App\Domain\Game\TurnResult;

/**
 * 讀預告的貪心策略：只看本回合淨傷害，打斷修復算進淨值，其他一概不管。
 *
 * 這是「會看畫面但不布局」的玩家。它應該明顯贏過隨機，但輸給會存資源
 * 抓破綻的規劃策略。
 */
class GreedyStrategy extends LookaheadStrategy
{
    public function name(): string
    {
        return 'greedy';
    }

    protected function score(BattleState $before, TurnResult $result, array $action): float
    {
        if ($result->state->outcome->value === 'player_victory') {
            return INF;
        }

        return (float) $this->coreDelta($before, $result);
    }
}
