<?php

namespace App\Services\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\BattleSimulator;
use App\Domain\Game\Simulation\Strategies\GreedyStrategy;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\Simulation\Strategy;
use App\Models\Run;

/**
 * P07 反事實比較：在一局已結束的對局裡換掉某一次決策，看剩下的回合交給
 * 模擬策略接手打完會是什麼結果（P04-REVISION-PLAN 驗收：「更換關鍵行動後，
 * 戰報跟著變；反事實結果來自模擬」）。
 *
 * 起點局面永遠從實際保存的行動重建，不是憑空捏造：第一筆決策之前用當時
 * 的 seed 與凍結牌組重新開局，之後每一筆用上一筆的 state_after。換掉那
 * 一次行動之後，續局呼叫的是和 `game:simulate` 完全相同的 BattleSimulator，
 * 所以同一組（局面, seed, 策略）永遠得到同一個比較結果，不會每次問都不同。
 *
 * 這個服務只讀 Run／RunAction，不寫入任何東西——反事實只是「如果」，不能
 * 改到玩家實際打出來的那一局。
 */
class CounterfactualComparator
{
    public function __construct(
        private readonly BattleEngine $engine,
        private readonly LevelRepository $levels,
        private readonly CardCatalog $cards,
    ) {}

    /**
     * @param  array{type: string, card_id: string|null, fixed: string|null, keep: list<string>}  $alternative
     * @return array<string, mixed>
     *
     * @throws InvalidActionException 換上去的行動本身不合法，或指定的回合不是可比較的決策
     */
    public function compare(Run $run, int $sequence, array $alternative, string $strategyName): array
    {
        $level = $this->levels->get($run->level_id);
        $modifiers = ScenarioModifiers::fromArray($run->scenario_modifiers);
        $actions = $run->actions;

        $target = $actions->firstWhere('sequence', $sequence);

        // 揭牌不是決策，沒有「換一個行動」這回事；找不到這筆序號同樣不能比較。
        if ($target === null || $target->input['type'] === 'reveal') {
            throw InvalidActionException::sequenceNotReplayable($sequence);
        }

        $before = $sequence === 1
            ? $this->engine->start($level, (int) $run->seed, $run->deck)
            : $this->stateBefore($actions->firstWhere('sequence', $sequence - 1), $sequence);

        $changed = $this->engine->apply(
            $before,
            new ActionRequest(
                actionId: 'counterfactual',
                expectedVersion: $before->version,
                type: ActionType::from($alternative['type']),
                cardId: $alternative['card_id'] ?? null,
                fixedSkillId: $alternative['fixed'] ?? null,
                keep: $alternative['keep'] ?? [],
            ),
            $level,
            $modifiers,
        );

        $strategy = $this->strategy($strategyName, $modifiers);
        $continued = (new BattleSimulator($this->engine))->continueFrom(
            $changed->state,
            $level,
            $modifiers,
            $strategy,
            (int) $run->seed,
            'counterfactual',
        );

        return [
            'sequence' => $sequence,
            'strategy' => $strategy->name(),
            'actual' => [
                'outcome' => $run->outcome->value,
                'turns' => (int) $run->state['turn'],
                'core_remaining' => (int) $run->state['core_resilience'],
            ],
            'counterfactual' => [
                'outcome' => $continued->outcome->value,
                'turns' => $continued->turns,
                'core_remaining' => $continued->coreRemaining,
                'diverged' => $continued->outcome->value !== $run->outcome->value,
            ],
            'continuation' => $continued->actions,
        ];
    }

    private function stateBefore(mixed $previous, int $sequence): BattleState
    {
        if ($previous === null) {
            throw InvalidActionException::sequenceNotReplayable($sequence);
        }

        return BattleState::fromArray($previous->state_after);
    }

    private function strategy(string $name, ScenarioModifiers $modifiers): Strategy
    {
        return match ($name) {
            'greedy' => new GreedyStrategy($modifiers),
            default => new PlannerStrategy($modifiers, $this->cards),
        };
    }
}
