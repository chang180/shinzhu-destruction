<?php

namespace App\Domain\Game\Phases;

use App\Domain\Game\CityIntent;
use App\Domain\Game\Element;
use App\Domain\Game\Exceptions\InvalidLevelConfigException;

/**
 * 一關裡的一幕。幕次只決定「城市這一段照哪張預告表行動」與玩家看到的目標；
 * 不改核心、防線、手牌、牌堆、惡意、印記、抗性、冷卻、護盾或 seed。
 *
 * 這和局面上的 `phase`（第 5 關重整的 standby／overhaul 機制狀態）是兩件事，
 * 存在 BattleState::$levelPhaseId，不共用欄位。
 */
final readonly class LevelPhaseDefinition
{
    /**
     * 設定檔可以排定的城市意圖。重整（overhaul）只由第 5 關機制在執行時產生，不能寫進預告表。
     */
    public const SCHEDULABLE_INTENTS = [CityIntent::TYPE_REPAIR, CityIntent::TYPE_SHIELD, CityIntent::TYPE_REINFORCE];

    /**
     * @param  array<int, array<string, mixed>>  $intents  回合 => 預告設定
     * @param  array<string, mixed>  $defaultIntent
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $objective,
        public PhaseTrigger $startsWhen,
        public array $intents,
        public array $defaultIntent,
    ) {}

    public static function fromConfig(string $levelId, int $maxTurns, mixed $definition): self
    {
        if (! is_array($definition)) {
            throw new InvalidLevelConfigException('phase_invalid', $levelId, '每一幕必須是陣列');
        }

        foreach (['id', 'label', 'objective'] as $key) {
            if (! is_string($definition[$key] ?? null) || $definition[$key] === '') {
                throw new InvalidLevelConfigException('phase_field_missing', $levelId, "每一幕都要有非空字串 {$key}");
            }
        }

        $id = $definition['id'];

        if (! array_key_exists('default_intent', $definition)) {
            throw new InvalidLevelConfigException('phase_missing_default_intent', $levelId, "phase {$id}: 缺少 default_intent");
        }

        if (! is_array($definition['intents'] ?? null)) {
            throw new InvalidLevelConfigException('phase_field_missing', $levelId, "phase {$id}: intents 必須是回合 => 意圖的陣列");
        }

        foreach ($definition['intents'] as $turn => $intent) {
            if (! is_int($turn) || $turn < 1 || $turn > $maxTurns) {
                throw new InvalidLevelConfigException('intent_turn_out_of_range', $levelId, "phase {$id}: 預告回合 {$turn} 不在 1～{$maxTurns}");
            }

            self::validateIntent($levelId, $id, "第 {$turn} 回合", $intent);
        }

        self::validateIntent($levelId, $id, 'default_intent', $definition['default_intent']);

        return new self(
            id: $id,
            label: $definition['label'],
            objective: $definition['objective'],
            startsWhen: PhaseTrigger::fromConfig($levelId, $id, $definition['starts_when'] ?? null),
            intents: $definition['intents'],
            defaultIntent: $definition['default_intent'],
        );
    }

    private static function validateIntent(string $levelId, string $phaseId, string $where, mixed $intent): void
    {
        $fail = static fn (string $message): InvalidLevelConfigException => new InvalidLevelConfigException('intent_invalid', $levelId, "phase {$phaseId} {$where}: {$message}");

        if (! is_array($intent)) {
            throw $fail('意圖必須是陣列');
        }

        if (! in_array($intent['type'] ?? null, self::SCHEDULABLE_INTENTS, true)) {
            throw $fail('type 只能是 '.implode('／', self::SCHEDULABLE_INTENTS));
        }

        if (! is_string($intent['element'] ?? null) || Element::tryFrom($intent['element']) === null) {
            throw $fail('element 必須是 '.implode('／', Element::values()));
        }

        if (! is_int($intent['magnitude'] ?? null) || $intent['magnitude'] < 0) {
            throw $fail('magnitude 必須是非負整數');
        }

        if (! is_bool($intent['interruptible'] ?? null)) {
            throw $fail('interruptible 必須是布林值');
        }
    }

    /**
     * 可公開的幕次資訊（不含預告表本身，預告仍走既有的 forecast／intent）。
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(int $order, ?self $next): array
    {
        return [
            'id' => $this->id,
            'order' => $order,
            'label' => $this->label,
            'objective' => $this->objective,
            'starts_when' => $this->startsWhen->toArray(),
            'starts_when_summary' => $this->startsWhen->describe(),
            'next_phase_summary' => $next?->startsWhen->describe(),
        ];
    }
}
