<?php

namespace App\Domain\Game\Scenario;

use App\Domain\Game\Element;

/**
 * 每系 -0.15～+0.15 的情境修正，以及每一項的推導理由。
 *
 * 這是**遊戲情境修正**，不是災害預測、風險評分或政策成效。理由字串會顯示在
 * 戰前情報，讓玩家看得到修正從哪個觀測期間的哪個指標來。
 */
final readonly class ScenarioModifiers
{
    /**
     * @param  array<string, float>  $modifiers  系別 => 修正值
     * @param  array<string, array{code: string, message: string, inputs: array<string, mixed>}>  $reasons
     */
    public function __construct(
        public array $modifiers,
        public array $reasons,
    ) {}

    public static function neutral(string $reasonCode = 'no_snapshot'): self
    {
        $modifiers = [];
        $reasons = [];

        foreach (Element::all() as $element) {
            $modifiers[$element->value] = 0.0;
            $reasons[$element->value] = [
                'code' => $reasonCode,
                'message' => '沒有可用資料，採中性修正',
                'inputs' => [],
            ];
        }

        return new self($modifiers, $reasons);
    }

    public function for(Element $element): float
    {
        return $this->modifiers[$element->value] ?? 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['modifiers' => $this->modifiers, 'reasons' => $this->reasons];
    }

    /**
     * @param  array<string, mixed>  $modifiers
     */
    public static function fromArray(array $modifiers): self
    {
        return new self($modifiers['modifiers'], $modifiers['reasons']);
    }
}
