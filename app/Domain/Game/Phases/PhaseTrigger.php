<?php

namespace App\Domain\Game\Phases;

use App\Domain\Game\Exceptions\InvalidLevelConfigException;

/**
 * 關卡幕次的進入條件。只支援 P10 需要的有限型別，不接受任意 callback 或腳本：
 *
 * - `turn_gte`：下一回合的回合數 ≥ value
 * - `core_lte`：核心韌性 ≤ value
 * - `flag_true`：局面旗標為真（只接受引擎真的會設定的旗標）
 * - `any_of`：任一子條件成立
 *
 * 條件在回合邊界檢查（城市回應與效果期限處理之後、推進回合之前），
 * 所以 turn_gte 看的是「即將開始的回合」。
 */
final readonly class PhaseTrigger
{
    public const TURN_GTE = 'turn_gte';

    public const CORE_LTE = 'core_lte';

    public const FLAG_TRUE = 'flag_true';

    public const ANY_OF = 'any_of';

    /**
     * 引擎實際會寫入局面的旗標與它們的公開說明。
     */
    public const FLAGS = [
        'first_interrupt_done' => '首次成功打斷之後',
        'overhaul_started' => '重整啟動之後',
        'overhaul_stopped' => '重整被中止之後',
    ];

    /**
     * @param  list<self>  $children
     */
    private function __construct(
        public string $type,
        public ?int $value = null,
        public ?string $flag = null,
        public array $children = [],
    ) {}

    public static function fromConfig(string $levelId, string $phaseId, mixed $definition): self
    {
        $fail = static fn (string $code, string $message): InvalidLevelConfigException => new InvalidLevelConfigException($code, $levelId, "phase {$phaseId}: {$message}");

        if (! is_array($definition) || ! is_string($definition['type'] ?? null)) {
            throw $fail('trigger_invalid', 'starts_when 必須是含 type 的陣列');
        }

        $allowedKeys = match ($definition['type']) {
            self::TURN_GTE, self::CORE_LTE => ['type', 'value'],
            self::FLAG_TRUE => ['type', 'flag'],
            self::ANY_OF => ['type', 'of'],
            default => throw $fail('trigger_unknown_type', "未知的 trigger 型別 {$definition['type']}"),
        };

        $extra = array_diff(array_keys($definition), $allowedKeys);

        if ($extra !== []) {
            throw $fail('trigger_unknown_field', "{$definition['type']} 不接受欄位 ".implode(', ', $extra));
        }

        return match ($definition['type']) {
            self::TURN_GTE, self::CORE_LTE => is_int($definition['value'] ?? null)
                ? new self($definition['type'], value: $definition['value'])
                : throw $fail('trigger_field_type', "{$definition['type']}.value 必須是整數"),
            self::FLAG_TRUE => match (true) {
                ! is_string($definition['flag'] ?? null) => throw $fail('trigger_field_type', 'flag_true.flag 必須是字串'),
                ! array_key_exists($definition['flag'], self::FLAGS) => throw $fail('trigger_unknown_flag', "引擎不會設定旗標 {$definition['flag']}"),
                default => new self(self::FLAG_TRUE, flag: $definition['flag']),
            },
            self::ANY_OF => match (true) {
                ! is_array($definition['of'] ?? null) || ! array_is_list($definition['of']) => throw $fail('trigger_field_type', 'any_of.of 必須是條件清單'),
                $definition['of'] === [] => throw $fail('trigger_empty_any_of', 'any_of 不能是空集合'),
                default => new self(self::ANY_OF, children: array_map(
                    static fn (mixed $child): self => self::fromConfig($levelId, $phaseId, $child),
                    $definition['of'],
                )),
            },
        };
    }

    /**
     * @param  array<string, mixed>  $flags
     */
    public function isSatisfied(int $nextTurn, int $core, array $flags): bool
    {
        return match ($this->type) {
            self::TURN_GTE => $nextTurn >= $this->value,
            self::CORE_LTE => $core <= $this->value,
            self::FLAG_TRUE => (bool) ($flags[$this->flag] ?? false),
            self::ANY_OF => array_any($this->children, static fn (self $child): bool => $child->isSatisfied($nextTurn, $core, $flags)),
        };
    }

    /**
     * 結構上是否可能成立。只排除設定本身就不可能的情況（回合超過上限、核心 0 時對局已結束、
     * 這一關根本不會設定的旗標）；能成立不代表任何一局一定會走到。
     *
     * @param  list<string>  $settableFlags
     */
    public function structurallyReachable(int $maxTurns, array $settableFlags): bool
    {
        return match ($this->type) {
            self::TURN_GTE => $this->value <= $maxTurns,
            self::CORE_LTE => $this->value >= 1,
            self::FLAG_TRUE => in_array($this->flag, $settableFlags, true),
            self::ANY_OF => array_any($this->children, static fn (self $child): bool => $child->structurallyReachable($maxTurns, $settableFlags)),
        };
    }

    /**
     * 純回合條件的門檻；其他型別回 null（無法拿來檢查順序）。
     */
    public function turnThreshold(): ?int
    {
        return $this->type === self::TURN_GTE ? $this->value : null;
    }

    /**
     * 可公開的條件摘要，供 API 與畫面說明「下一幕何時開始」。
     */
    public function describe(): string
    {
        return match ($this->type) {
            self::TURN_GTE => "第 {$this->value} 回合起",
            self::CORE_LTE => "城市核心降到 {$this->value} 以下",
            self::FLAG_TRUE => self::FLAGS[$this->flag],
            self::ANY_OF => implode('，或', array_map(static fn (self $child): string => $child->describe(), $this->children)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'value' => $this->value,
            'flag' => $this->flag,
            'of' => $this->children === [] ? null : array_map(static fn (self $child): array => $child->toArray(), $this->children),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
