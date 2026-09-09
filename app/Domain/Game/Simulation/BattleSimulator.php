<?php

namespace App\Domain\Game\Simulation;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Scenario\ScenarioModifiers;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * 跑完一整局自動對戰。
 *
 * seed 同時決定牌序與策略的選擇，所以 (關卡, 情境, 策略, seed) 完全決定結果，
 * 任何一場都能重跑。
 *
 * 模擬一律跑不限時：策略沒有反應速度問題，逾時只會測到「我沒有送出行動」，
 * 量不到打法好壞。揭牌不是策略決策，由模擬器自動送出。
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
        $state = $this->engine->start($level, $seed);
        $actions = [];
        $rejected = 0;
        $counter = 0;

        while (! $state->outcome->isFinished()) {
            if ($state->turnPhase === BattleState::PHASE_AWAITING_REVEAL) {
                $state = $this->engine->apply(
                    $state,
                    new ActionRequest('sim-reveal-'.(++$counter), $state->version, ActionType::Reveal),
                    $level,
                    $modifiers,
                )->state;

                continue;
            }

            $choice = $strategy->choose($state, $this->engine, $level, $rng);

            if ($choice === null) {
                break;
            }

            $request = $this->request('sim-'.(++$counter), $state, $choice);

            try {
                $result = $this->engine->apply($state, $request, $level, $modifiers);
            } catch (InvalidActionException) {
                // 策略挑了非法動作是策略的錯，不是引擎的；記錄並退回蓄勢，
                // 避免無限迴圈把問題藏起來。
                $rejected++;

                if ($rejected > $level->maxTurns * 2) {
                    break;
                }

                $choice = ['type' => 'play', 'card_id' => null, 'fixed' => 'gather', 'skill_id' => 'gather', 'target' => null];
                $result = $this->engine->apply(
                    $state,
                    $this->request('sim-fallback-'.$counter, $state, $choice),
                    $level,
                    $modifiers,
                );
            }

            $actions[] = [
                'turn' => $state->turn,
                'type' => $choice['type'],
                'card_id' => $choice['card_id'] ?? null,
                'skill_id' => $choice['skill_id'] ?? null,
                'target' => $choice['target'] ?? null,
            ];
            $state = $result->state;
        }

        return new SimulationResult(
            levelId: $level->id,
            strategy: $strategy->name(),
            scenario: $scenarioLabel,
            seed: $seed,
            outcome: $state->outcome,
            turns: $state->turn,
            coreRemaining: $state->coreResilience,
            rejectedActions: $rejected,
            actions: $actions,
        );
    }

    /**
     * 把策略挑到的行動描述轉成提交。留牌交給策略決定，沒有指定就全部棄掉。
     *
     * @param  array<string, mixed>  $choice
     */
    private function request(string $actionId, BattleState $state, array $choice): ActionRequest
    {
        return new ActionRequest(
            actionId: $actionId,
            expectedVersion: $state->version,
            type: ActionType::from($choice['type'] ?? 'play'),
            cardId: $choice['card_id'] ?? null,
            fixedSkillId: $choice['fixed'] ?? null,
            keep: $choice['keep'] ?? [],
        );
    }

    private function seedString(int $seed): string
    {
        return hash('sha256', 'shinzhu:'.$seed, true);
    }
}
