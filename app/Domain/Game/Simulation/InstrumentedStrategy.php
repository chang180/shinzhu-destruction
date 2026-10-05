<?php

namespace App\Domain\Game\Simulation;

/**
 * 需要逐局狀態與量測紀錄的策略。模擬器在每局開始前呼叫 beginGame()，
 * 結束後把 gameReport() 寫進 SimulationResult::$strategyReport。
 *
 * 策略物件會在不同 seed 之間重用，逐局狀態一律在 beginGame() 重置，
 * 不要從回合數猜「是不是新的一局」。
 */
interface InstrumentedStrategy extends Strategy
{
    public function beginGame(): void;

    /**
     * @return array<string, mixed>
     */
    public function gameReport(): array;
}
