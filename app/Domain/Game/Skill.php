<?php

namespace App\Domain\Game;

use InvalidArgumentException;

/**
 * 技能定義。id 是穩定代碼（例如 `probe.water`、`ultimate`），UI 名稱由前端包裝，
 * 規則計算只認代碼。
 */
final readonly class Skill
{
    public function __construct(
        public string $id,
        public SkillKind $kind,
        public ?Element $element,
        public int $maliceCost,
        public int $cooldown,
        public int $baseImpact,
        public int $defenseDelta,
        public int $sigilGain,
        public int $maliceRefund = 0,
        public int $resistanceRelief = 0,
        public int $requiredSigilsPerElement = 0,
    ) {}

    public static function id(SkillKind $kind, ?Element $element): string
    {
        return $element === null ? $kind->value : $kind->value.'.'.$element->value;
    }

    /**
     * @param  array<string, mixed>  $definition  config/game.php 的單一技能設定
     */
    public static function fromConfig(array $definition, ?Element $element): self
    {
        $kind = SkillKind::from($definition['kind']);

        if ($kind->isElemental() && $element === null) {
            throw new InvalidArgumentException("技能 {$kind->value} 必須指定系別");
        }

        if (! $kind->isElemental() && $element !== null) {
            throw new InvalidArgumentException("技能 {$kind->value} 不接受系別");
        }

        return new self(
            id: self::id($kind, $element),
            kind: $kind,
            element: $element,
            maliceCost: $definition['malice_cost'],
            cooldown: $definition['cooldown'],
            baseImpact: $definition['base_impact'],
            defenseDelta: $definition['defense_delta'],
            sigilGain: $definition['sigil_gain'],
            maliceRefund: $definition['malice_refund'] ?? 0,
            resistanceRelief: $definition['resistance_relief'] ?? 0,
            requiredSigilsPerElement: $definition['required_sigils_per_element'] ?? 0,
        );
    }
}
