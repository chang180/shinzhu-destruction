<?php

namespace App\Domain\Game;

/**
 * 一關的完整規則來源。P03 只實作三個代表關卡驗證引擎；13 關在 P05 補齊，
 * 但欄位形狀在這裡固定下來，P05 只新增設定不改引擎介面。
 */
final readonly class LevelDefinition
{
    /**
     * @param  array<string, int>  $defenses  系別 => 初始防線
     * @param  list<string>  $dataElements  這一關會受情境修正影響的系別
     * @param  array<int, array<string, mixed>>  $intents  回合 => 預告設定
     * @param  array<string, mixed>  $defaultIntent
     * @param  list<int>  $pulseTurns
     * @param  array<string, mixed>|null  $overhaul
     * @param  list<string>  $phases
     */
    public function __construct(
        public string $id,
        public int $sequence,
        public string $name,
        public string $apostle,
        public int $maxTurns,
        public ?string $requires,
        public array $defenses,
        public array $dataElements,
        public string $apostlePower,
        public int $apostlePowerValue,
        public array $intents,
        public array $defaultIntent,
        public array $pulseTurns = [],
        public ?array $overhaul = null,
        public array $phases = [],
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromConfig(string $id, array $definition): self
    {
        return new self(
            id: $id,
            sequence: $definition['sequence'],
            name: $definition['name'],
            apostle: $definition['apostle'],
            maxTurns: $definition['max_turns'],
            requires: $definition['requires'],
            defenses: $definition['defenses'],
            dataElements: $definition['data_elements'],
            apostlePower: $definition['apostle_power'],
            apostlePowerValue: $definition['apostle_power_value'],
            intents: $definition['intents'],
            defaultIntent: $definition['default_intent'],
            pulseTurns: $definition['pulse_turns'] ?? [],
            overhaul: $definition['overhaul'] ?? null,
            phases: $definition['phases'] ?? [],
        );
    }

    public function isPulseTurn(int $turn): bool
    {
        return in_array($turn, $this->pulseTurns, true);
    }

    public function affectsElement(Element $element): bool
    {
        return in_array($element->value, $this->dataElements, true);
    }

    /**
     * 第 turn 回合結束時城市會做的事。回合表沒有指定就用預設意圖，
     * 因此每一回合都有可讀的預告，不會出現「城市這回合不知道要幹嘛」。
     */
    public function intentForTurn(int $turn): CityIntent
    {
        $definition = $this->intents[$turn] ?? $this->defaultIntent;

        return new CityIntent(
            type: $definition['type'],
            element: Element::from($definition['element']),
            magnitude: $definition['magnitude'],
            interruptible: $definition['interruptible'],
            scheduledTurn: $turn,
            description: $this->describe($definition, $turn),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function describe(array $definition, int $turn): string
    {
        $element = Element::from($definition['element'])->label();
        $magnitude = $definition['magnitude'];
        $interruptible = $definition['interruptible']
            ? "可用{$element}系擾序打斷"
            : '無法打斷';

        $what = match ($definition['type']) {
            CityIntent::TYPE_REPAIR => "回復核心韌性 {$magnitude}",
            CityIntent::TYPE_SHIELD => "架起 {$magnitude} 點{$element}系護盾",
            CityIntent::TYPE_REINFORCE => "補強{$element}系防線 {$magnitude}",
            CityIntent::TYPE_OVERHAUL => "完成重整並回復核心韌性 {$magnitude}",
            default => "執行 {$definition['type']}",
        };

        return "第 {$turn} 回合結束時：{$what}（{$interruptible}）";
    }
}
