<?php

namespace App\Domain\Game\Simulation;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\Element;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Scenario\ScenarioModifiers;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * 跑完一整局自動對戰。
 *
 * 引擎本身沒有亂數，唯一的亂數來源是策略；seed 決定策略的選擇，
 * 所以 (關卡, 情境, 策略, seed) 完全決定結果，任何一場都能重跑。
 */
class BattleSimulator
{
    public function __construct(private readonly BattleEngine $engine) {}

    public function run(
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
        Strategy $strategy,
        int $seed,
        string $scenarioLabel,
    ): SimulationResult {
        $rng = new Randomizer(new Xoshiro256StarStar($this->seedString($seed)));
        $state = $this->engine->start($level);
        $actions = [];
        $rejected = 0;
        $counter = 0;

        while (! $state->outcome->isFinished()) {
            $choice = $strategy->choose($state, $this->engine, $level, $rng);

            if ($choice === null) {
                break;
            }

            $request = new ActionRequest(
                actionId: 'sim-'.(++$counter),
                expectedVersion: $state->version,
                skillId: $choice['skill_id'],
                target: $choice['target'] === null ? null : Element::from($choice['target']),
            );

            try {
                $result = $this->engine->apply($state, $request, $level, $modifiers);
            } catch (InvalidActionException) {
                // 策略挑了非法動作是策略的錯，不是引擎的；記錄並退回蓄勢，
                // 避免無限迴圈把問題藏起來。
                $rejected++;

                if ($rejected > $level->maxTurns * 2) {
                    break;
                }

                $result = $this->engine->apply(
                    $state,
                    new ActionRequest('sim-fallback-'.$counter, $state->version, 'gather', null),
                    $level,
                    $modifiers,
                );

                $choice = ['skill_id' => 'gather', 'target' => null];
            }

            $actions[] = ['turn' => $state->turn, 'skill_id' => $choice['skill_id'], 'target' => $choice['target']];
            $state = $result->state;
        }

        return new SimulationResult(
            levelId: $level->id,
            strategy: $strategy->name(),
            scenario: $scenarioLabel,
            seed: $seed,
            outcome: $state->outcome,
            turns: count($actions),
            coreRemaining: $state->coreResilience,
            rejectedActions: $rejected,
            actions: $actions,
        );
    }

    private function seedString(int $seed): string
    {
        return hash('sha256', 'shinzhu:'.$seed, true);
    }
}
