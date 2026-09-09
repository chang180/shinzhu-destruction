<?php

namespace App\Domain\Game;

/**
 * 一關的完整規則來源。
 *
 * 戰役固定為 3 關主線＋2 關進階（P04-REVISION-PLAN §2）。P04 只把第 1 關做到
 * 可玩可驗收，第 2～5 關在設定裡佔住穩定 ID、順序與依賴，`available` 為 false
 * 代表內容還沒做完——不是解鎖狀態，而是「這一關的規則與數值尚未交付」。
 */
final readonly class LevelDefinition
{
    /**
     * @param  array<string, int>  $defenses  系別 => 初始防線
     * @param  list<string>  $dataElements  這一關會受情境修正影響的系別
     * @param  array<string, int>  $deck  牌型 => 張數
     * @param  array<int, array<string, mixed>>  $intents  回合 => 預告設定
     * @param  array<string, mixed>  $defaultIntent
     * @param  list<int>  $pulseTurns
     * @param  array<string, mixed>|null  $overhaul
     * @param  list<string>  $phases
     */
    public function __construct(
        public string $id,
        public int $sequence,
        public string $tier,
        public bool $available,
        public string $name,
        public string $subtitle,
        public string $apostle,
        public int $maxTurns,
        public ?string $requires,
        public array $defenses,
        public array $dataElements,
        public array $deck,
        public string $mechanic,
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
            tier: $definition['tier'],
            available: $definition['available'],
            name: $definition['name'],
            subtitle: $definition['subtitle'],
            apostle: $definition['apostle'],
            maxTurns: $definition['max_turns'],
            requires: $definition['requires'],
            defenses: $definition['defenses'],
            dataElements: $definition['data_elements'],
            deck: $definition['deck'],
            mechanic: $definition['mechanic'],
            apostlePower: $definition['apostle_power'],
            apostlePowerValue: $definition['apostle_power_value'],
            intents: $definition['intents'],
            defaultIntent: $definition['default_intent'],
            pulseTurns: $definition['pulse_turns'] ?? [],
            overhaul: $definition['overhaul'] ?? null,
            phases: $definition['phases'] ?? [],
        );
    }

    public function deckSize(): int
    {
        return (int) array_sum($this->deck);
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
