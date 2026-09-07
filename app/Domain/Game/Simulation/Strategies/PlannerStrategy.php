<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleState;
use App\Domain\Game\Element;
use App\Domain\Game\TurnResult;

/**
 * 會布局的規劃策略：除了本回合淨傷害，還替後續留價值。
 *
 * 加分項全部來自玩家看得到的機制——開破綻、保住連攜、留惡意給終招、
 * 壓低適應抗性——沒有任何一項需要偷看關卡表。
 */
class PlannerStrategy extends LookaheadStrategy
{
    public function name(): string
    {
        return 'planner';
    }

    protected function score(BattleState $before, TurnResult $result, array $action): float
    {
        $state = $result->state;

        if ($state->outcome->value === 'player_victory') {
            return INF;
        }

        if ($state->outcome->value === 'city_held') {
            return -INF;
        }

        $score = (float) $this->coreDelta($before, $result);

        // 開出破綻等於替下一招換到 ×1.25，值得為它少打一點。
        if ($this->hasEvent($result, 'breach_opened')) {
            $score += 6.0;
        }

        // 破綻窗口不留到過期：帶著破綻進下一回合反而扣分，逼策略用掉它。
        if ($state->breachAvailable) {
            $score -= 2.0;
        }

        // 成功打斷除了省下修復量，還讓城市少一次行動。
        if ($this->hasEvent($result, 'interrupt')) {
            $score += 3.0;
        }

        // 適應抗性越低，後面每一招都更痛。
        $resistanceBefore = array_sum($before->resistance);
        $resistanceAfter = array_sum($state->resistance);
        $score += ($resistanceBefore - $resistanceAfter) * 2.0;

        // 印記是終招的入場券，但只在還沒滿足條件時值錢。
        $score += $this->sigilProgress($before, $state) * 1.5;

        // 惡意留在手上有選擇權，但只是小加權，不能變成什麼都不做。
        $score += $state->malice * 0.25;

        return $score;
    }

    private function sigilProgress(BattleState $before, BattleState $after): float
    {
        $required = 2;
        $progress = 0.0;

        foreach (Element::all() as $element) {
            $wasShort = max(0, $required - $before->sigil($element));
            $isShort = max(0, $required - $after->sigil($element));
            $progress += $wasShort - $isShort;
        }

        return $progress;
    }
}
