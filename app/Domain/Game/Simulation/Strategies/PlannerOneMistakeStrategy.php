<?php

namespace App\Domain\Game\Simulation\Strategies;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Simulation\InstrumentedStrategy;
use Random\Randomizer;

/**
 * 規劃策略，但在一個可重現的關鍵窗口改出「語義不同且 planner 評分嚴格較低」的合法行動，
 * 用來量測關卡的恢復空間（P10 §3.2）。
 *
 * 關鍵窗口只看玩家畫面上的資訊：第 2 回合起第一個「可打斷」的預告回合；
 * 打到一半（回合數過半）都沒遇到，就在過半那一回合。每局只有這一個窗口。
 *
 * 語義簽章是 type／skill_id／target／fixed：同招同系的另一張實體牌不算失誤，
 * 和最佳行動同分的也不算。窗口裡找不到符合條件的行動就照 planner 出牌並標記
 * no_eligible_mistake，不硬塞一個假失誤。
 */
class PlannerOneMistakeStrategy extends PlannerStrategy implements InstrumentedStrategy
{
    public const STATUS_INJECTED = 'injected';

    public const STATUS_NO_ELIGIBLE = 'no_eligible_mistake';

    public const STATUS_WINDOW_NOT_REACHED = 'window_not_reached';

    /**
     * @var array<string, mixed>
     */
    private array $report = [];

    public function name(): string
    {
        return 'planner-one-mistake';
    }

    public function beginGame(): void
    {
        $this->report = ['status' => self::STATUS_WINDOW_NOT_REACHED];
    }

    public function gameReport(): array
    {
        return $this->report;
    }

    public function choose(BattleState $state, BattleEngine $engine, LevelDefinition $level, Randomizer $rng): ?array
    {
        if ($this->report === []) {
            $this->beginGame();
        }

        if ($this->report['status'] !== self::STATUS_WINDOW_NOT_REACHED || ! $this->isKeyWindow($state)) {
            return parent::choose($state, $engine, $level, $rng);
        }

        $ranked = $this->ranked($state, $engine, $level);

        if ($ranked === []) {
            $this->report = ['status' => self::STATUS_NO_ELIGIBLE, 'turn' => $state->turn, 'best' => null, 'mistake' => null];

            return parent::choose($state, $engine, $level, $rng);
        }

        [$best, $bestScore] = $ranked[0];
        $mistake = null;

        foreach (array_slice($ranked, 1) as [$action, $score]) {
            if ($score < $bestScore && self::signature($action) !== self::signature($best)) {
                $mistake = [$action, $score];

                break;
            }
        }

        $legacy = $ranked[1] ?? null;
        $this->report = [
            'status' => $mistake === null ? self::STATUS_NO_ELIGIBLE : self::STATUS_INJECTED,
            'turn' => $state->turn,
            'best' => self::describe($best, $bestScore),
            'mistake' => $mistake === null ? null : self::describe(...$mistake),
            'score_delta' => $mistake === null ? null : self::number($bestScore - $mistake[1]),
            // P10-0（p10-di-1）直接取排名第二；留下它和最佳行動的關係，供新舊語意對照。
            'legacy_second' => $legacy === null ? null : self::describe(...$legacy),
            'legacy_second_same_semantics' => $legacy === null ? null : self::signature($legacy[0]) === self::signature($best),
            'legacy_second_tied' => $legacy === null ? null : $legacy[1] === $bestScore,
        ];

        if ($mistake === null) {
            return parent::choose($state, $engine, $level, $rng);
        }

        $action = $mistake[0];
        $action['keep'] = $this->keep($state, $action);

        return $action;
    }

    /**
     * 行動的語義簽章：同招、同系、同固定行動視為同一個決策，不看實體牌 ID。
     *
     * @param  array<string, mixed>  $action
     */
    public static function signature(array $action): string
    {
        return implode('|', [
            $action['type'] ?? '',
            $action['skill_id'] ?? '',
            $action['target'] ?? '',
            $action['fixed'] ?? '',
        ]);
    }

    private function isKeyWindow(BattleState $state): bool
    {
        if ($state->turn < 2) {
            return false;
        }

        return ($state->intent?->interruptible ?? false)
            || $state->turn >= (int) ceil($state->maxTurns / 2);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private static function describe(array $action, float $score): array
    {
        return [
            'type' => $action['type'],
            'skill_id' => $action['skill_id'],
            'target' => $action['target'],
            'fixed' => $action['fixed'],
            'card_id' => $action['card_id'],
            'score' => self::number($score),
        ];
    }

    /**
     * JSON 不能表示無限大；勝利一手的 INF 改寫成字串，其餘四捨五入到 4 位。
     */
    private static function number(float $value): float|string
    {
        return is_infinite($value) ? ($value > 0 ? 'INF' : '-INF') : round($value, 4);
    }
}
