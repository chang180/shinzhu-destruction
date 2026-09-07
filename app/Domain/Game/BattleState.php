<?php

namespace App\Domain\Game;

/**
 * 伺服器權威局面。這是規則計算與存檔的唯一形狀；Vue 只顯示它，不重算。
 *
 * 引擎每次結算都先 `copy()` 再改寫，原狀態保持不變，方便反事實比較
 * （P07）與測試對照。
 */
final class BattleState
{
    /**
     * @param  array<string, int>  $defenses  系別 => 0..100
     * @param  array<string, int>  $sigils  系別 => 0..sigilCap
     * @param  array<string, int>  $resistance  系別 => 適應抗性層數
     * @param  array<string, int>  $cooldowns  技能代碼 => ready_on_turn
     * @param  list<array{element: string, amount: int, expires_on_turn: int}>  $shields
     * @param  list<string>  $comboChain  最近的進攻系別，最多保留 3 筆
     * @param  array<string, bool>  $breachedElements  防線曾降到 0 且尚未回補到可再觸發
     * @param  array<string, mixed>  $flags  一次性觸發旗標與關卡計數器
     */
    public function __construct(
        public int $turn,
        public int $maxTurns,
        public int $coreResilience,
        public array $defenses,
        public array $sigils,
        public array $resistance,
        public array $cooldowns,
        public array $shields,
        public int $malice,
        public int $maliceCap,
        public int $sigilCap,
        public array $comboChain,
        public bool $breachAvailable,
        public array $breachedElements,
        public ?string $lastAttackElement,
        public ?CityIntent $intent,
        public string $phase,
        public array $flags,
        public int $version,
        public Outcome $outcome,
    ) {}

    public function copy(): self
    {
        return new self(
            $this->turn,
            $this->maxTurns,
            $this->coreResilience,
            $this->defenses,
            $this->sigils,
            $this->resistance,
            $this->cooldowns,
            $this->shields,
            $this->malice,
            $this->maliceCap,
            $this->sigilCap,
            $this->comboChain,
            $this->breachAvailable,
            $this->breachedElements,
            $this->lastAttackElement,
            $this->intent,
            $this->phase,
            $this->flags,
            $this->version,
            $this->outcome,
        );
    }

    public function defense(Element $element): int
    {
        return $this->defenses[$element->value];
    }

    public function resistanceLayers(Element $element): int
    {
        return $this->resistance[$element->value];
    }

    public function sigil(Element $element): int
    {
        return $this->sigils[$element->value];
    }

    /**
     * 命中前的有效防線：實際防線加上適應抗性層數換算的加值，夾在 0..100。
     */
    public function effectiveDefense(Element $element, int $defensePerLayer): int
    {
        return max(0, min(100, $this->defense($element) + $this->resistanceLayers($element) * $defensePerLayer));
    }

    public function totalShield(): int
    {
        return array_sum(array_column($this->shields, 'amount'));
    }

    public function skillReady(string $skillId): bool
    {
        return ($this->cooldowns[$skillId] ?? 0) <= $this->turn;
    }

    public function turnsRemaining(): int
    {
        return max(0, $this->maxTurns - $this->turn + 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'turn' => $this->turn,
            'max_turns' => $this->maxTurns,
            'core_resilience' => $this->coreResilience,
            'defenses' => $this->defenses,
            'sigils' => $this->sigils,
            'resistance' => $this->resistance,
            'cooldowns' => $this->cooldowns,
            'shields' => $this->shields,
            'malice' => $this->malice,
            'malice_cap' => $this->maliceCap,
            'sigil_cap' => $this->sigilCap,
            'combo_chain' => $this->comboChain,
            'breach_available' => $this->breachAvailable,
            'breached_elements' => $this->breachedElements,
            'last_attack_element' => $this->lastAttackElement,
            'intent' => $this->intent?->toArray(),
            'phase' => $this->phase,
            'flags' => $this->flags,
            'version' => $this->version,
            'outcome' => $this->outcome->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            $state['turn'],
            $state['max_turns'],
            $state['core_resilience'],
            $state['defenses'],
            $state['sigils'],
            $state['resistance'],
            $state['cooldowns'],
            $state['shields'],
            $state['malice'],
            $state['malice_cap'],
            $state['sigil_cap'],
            $state['combo_chain'],
            $state['breach_available'],
            $state['breached_elements'],
            $state['last_attack_element'],
            $state['intent'] === null ? null : CityIntent::fromArray($state['intent']),
            $state['phase'],
            $state['flags'],
            $state['version'],
            Outcome::from($state['outcome']),
        );
    }
}
