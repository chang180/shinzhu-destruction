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
     * @param  float|null  $modifierCap  這一關對每系情境修正的額外上限（三系綜合的關卡用）
     * @param  array<string, mixed>|null  $adaptiveShield  城市鏡射玩家上一次進攻系別的護盾
     * @param  array<string, mixed>|null  $reward  通關後的牌組獎勵（兩選一）
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
        public string $lesson,
        /** @var array<string, mixed> */
        public array $briefing,
        public string $apostlePower,
        public int $apostlePowerValue,
        public array $intents,
        public array $defaultIntent,
        public array $pulseTurns = [],
        public ?array $overhaul = null,
        public ?float $modifierCap = null,
        public ?array $adaptiveShield = null,
        public ?array $reward = null,
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
            lesson: $definition['lesson'],
            briefing: $definition['briefing'],
            apostlePower: $definition['apostle_power'],
            apostlePowerValue: $definition['apostle_power_value'],
            intents: $definition['intents'],
            defaultIntent: $definition['default_intent'],
            pulseTurns: $definition['pulse_turns'] ?? [],
            overhaul: $definition['overhaul'] ?? null,
            modifierCap: $definition['modifier_cap'] ?? null,
            adaptiveShield: $definition['adaptive_shield'] ?? null,
            reward: $definition['reward'] ?? null,
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

    /**
     * 這一關實際採用的情境修正。關卡沒有設上限就照原值，設了就夾在 ±cap——
     * 三系同時採用的關卡若不夾，好資料與壞資料的差距會直接決定勝負。
     */
    public function cappedModifier(float $modifier): float
    {
        if ($this->modifierCap === null) {
            return $modifier;
        }

        return max(-$this->modifierCap, min($this->modifierCap, $modifier));
    }

    public function hasScheduledIntent(int $turn): bool
    {
        return array_key_exists($turn, $this->intents);
    }

    /**
     * 這一回合的預告是否由「鏡射玩家上一次進攻的系別」決定。
     * 回合表寫死的預告優先——那是玩家開局就讀得到的固定行程。
     */
    public function mirrorsPlayerOnTurn(int $turn): bool
    {
        return $this->adaptiveShield !== null
            && ! $this->hasScheduledIntent($turn)
            && $turn >= $this->adaptiveShield['from_turn'];
    }

    public function affectsElement(Element $element): bool
    {
        return in_array($element->value, $this->dataElements, true);
    }

    /**
     * 第 turn 回合結束時城市會做的事。回合表沒有指定就用預設意圖，
     * 因此每一回合都有可讀的預告，不會出現「城市這回合不知道要幹嘛」。
     */
    public function intentForTurn(int $turn, ?Element $mirrored = null): CityIntent
    {
        if ($this->mirrorsPlayerOnTurn($turn)) {
            return $this->mirrorIntent($turn, $mirrored);
        }

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
     * 鏡蔭的護盾：城市讀玩家最近一次進攻的系別，下一回合就擋那一系。
     *
     * $mirrored 為 null 代表「還沒有人出手」或這是開局就能讀到的靜態預告表，
     * 此時退回預設系別並在說明裡寫明規則，不假裝城市已經選好了。
     */
    private function mirrorIntent(int $turn, ?Element $mirrored): CityIntent
    {
        $magnitude = $this->adaptiveShield['magnitude'];
        $element = $mirrored ?? Element::from($this->defaultIntent['element']);
        $description = $mirrored === null
            ? "第 {$turn} 回合結束時：城市鏡射你最近一次進攻的系別，架起 {$magnitude} 點同系護盾（可用該系擾序打斷）"
            : "第 {$turn} 回合結束時：城市讀到你上一次的{$element->label()}系進攻，架起 {$magnitude} 點{$element->label()}系護盾（可用{$element->label()}系擾序打斷）";

        return new CityIntent(
            type: CityIntent::TYPE_SHIELD,
            element: $element,
            magnitude: $magnitude,
            interruptible: true,
            scheduledTurn: $turn,
            description: $description,
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
