<?php

use App\Domain\Game\Element;

return [

    /*
    |--------------------------------------------------------------------------
    | 規則版本
    |--------------------------------------------------------------------------
    |
    | 每一局在建立時記下 rules_version，之後永遠用該版本重播。改動下面任何
    | 數值、公式或結算順序都必須升版，否則舊局的重播會與原本的事件序列不符。
    | 公式與理由見 docs/BALANCE.md。
    |
    */

    'rules_version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | 局面初始值
    |--------------------------------------------------------------------------
    */

    'initial' => [
        'core_resilience' => 100,
        'defense' => 40,
        'malice' => 6,
        'malice_cap' => 10,
        'malice_regen' => 3,
        'sigil_cap' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | 傷害公式常數
    |--------------------------------------------------------------------------
    |
    | raw = floor(base × (1 + env) × combo × (1 - min(cap, effective_defense / divisor)))
    |
    */

    'damage' => [
        'defense_divisor' => 120,
        'defense_reduction_cap' => 0.75,
        'combo_multiplier' => 1.2,
        'breach_multiplier' => 1.25,
        'environment_modifier_limit' => 0.15,
    ],

    /*
    |--------------------------------------------------------------------------
    | 適應抗性與破綻
    |--------------------------------------------------------------------------
    |
    | 同系連續第 2 次及之後每次 +1 層（最多 3 層），每層等效防線 +10。
    | 加層發生在本次命中「之後」，本次命中一律使用命中前的層數。
    |
    */

    'resistance' => [
        'max_layers' => 3,
        'defense_per_layer' => 10,
    ],

    'breach' => [
        // 防線降到 0 才觸發；同一條防線要回到這個值以上才能再次觸發。
        'rearm_defense' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | 技能表
    |--------------------------------------------------------------------------
    |
    | id 是穩定代碼，UI 名稱由前端包裝。cooldown 以「接下來幾個玩家決策回合
    | 不可使用」計算，ready_on_turn = 使用回合 + cooldown + 1。
    |
    */

    'skills' => [
        'probe' => [
            'kind' => 'probe',
            'malice_cost' => 2,
            'cooldown' => 0,
            'base_impact' => 15,
            'defense_delta' => -8,
            'sigil_gain' => 1,
            'elemental' => true,
        ],
        'breach' => [
            'kind' => 'breach',
            'malice_cost' => 5,
            'cooldown' => 3,
            'base_impact' => 28,
            'defense_delta' => -20,
            'sigil_gain' => 1,
            'elemental' => true,
        ],
        'disrupt' => [
            'kind' => 'disrupt',
            'malice_cost' => 3,
            'cooldown' => 3,
            'base_impact' => 10,
            'defense_delta' => -4,
            'sigil_gain' => 0,
            'elemental' => true,
        ],
        'gather' => [
            'kind' => 'gather',
            'malice_cost' => 0,
            'cooldown' => 0,
            'base_impact' => 0,
            'defense_delta' => 0,
            'sigil_gain' => 0,
            'elemental' => false,
            'malice_refund' => 3,
            'resistance_relief' => 1,
        ],
        'ultimate' => [
            'kind' => 'ultimate',
            'malice_cost' => 6,
            'cooldown' => 4,
            'base_impact' => 55,
            'defense_delta' => -8,
            'sigil_gain' => 0,
            'elemental' => false,
            'required_sigils_per_element' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 情境修正
    |--------------------------------------------------------------------------
    |
    | 由 P02 的正規化快照換算成每系 -15%～+15% 的遊戲修正。公式見
    | docs/BALANCE.md §4；缺資料一律回中性 0，不猜測。
    |
    */

    'scenario' => [
        // 相對指標（storage_index、demand_index）偏離 1.0 多少即達到訊號飽和。
        'ratio_saturation' => 0.10,
        'modifier_limit' => 0.15,
    ],

    /*
    |--------------------------------------------------------------------------
    | 代表關卡
    |--------------------------------------------------------------------------
    |
    | P03 只實作三個代表情境驗證規則；13 關在 P05 補齊。城市區域名是虛構關卡
    | 命名，不對真實鄉鎮市做風險排序。
    |
    */

    'levels' => [

        'empty-cup' => [
            'sequence' => 1,
            'name' => '空杯使徒・最後一滴學分',
            'apostle' => 'empty-cup',
            'max_turns' => 10,
            'requires' => null,
            'defenses' => [
                Element::Water->value => 46,
                Element::Heat->value => 36,
                Element::Land->value => 38,
            ],
            'data_elements' => [Element::Water->value],
            'apostle_power' => 'interrupt_refund',
            'apostle_power_value' => 2,
            'intents' => [
                3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 16, 'interruptible' => true],
                6 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 20, 'interruptible' => true],
                9 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 24, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'water', 'magnitude' => 5, 'interruptible' => false],
            'phases' => [],
        ],

        'meter-feast' => [
            'sequence' => 5,
            'name' => '饗表使徒・無底的需求',
            'apostle' => 'meter-feast',
            'max_turns' => 13,
            'requires' => 'empty-cup',
            'defenses' => [
                Element::Water->value => 44,
                Element::Heat->value => 44,
                Element::Land->value => 44,
            ],
            'data_elements' => [Element::Water->value, Element::Heat->value],
            'apostle_power' => 'pulse_combo_refund',
            'apostle_power_value' => 1,
            // 需求脈衝回合：城市防禦換效率，修復量更大但護盾更薄。
            'pulse_turns' => [4, 8, 12],
            'intents' => [
                2 => ['type' => 'shield', 'element' => 'heat', 'magnitude' => 18, 'interruptible' => true],
                4 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 20, 'interruptible' => true],
                6 => ['type' => 'shield', 'element' => 'water', 'magnitude' => 20, 'interruptible' => true],
                8 => ['type' => 'repair', 'element' => 'heat', 'magnitude' => 24, 'interruptible' => true],
                10 => ['type' => 'shield', 'element' => 'heat', 'magnitude' => 22, 'interruptible' => true],
                12 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 26, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'land', 'magnitude' => 6, 'interruptible' => false],
            'phases' => [],
        ],

        'stored-night' => [
            'sequence' => 9,
            'name' => '蓄夜使徒・修復之前',
            'apostle' => 'stored-night',
            'max_turns' => 15,
            'requires' => 'meter-feast',
            'defenses' => [
                Element::Water->value => 58,
                Element::Heat->value => 54,
                Element::Land->value => 56,
            ],
            'data_elements' => [Element::Water->value, Element::Heat->value, Element::Land->value],
            'apostle_power' => 'overhaul_stop_breach',
            'apostle_power_value' => 1,
            /*
             * 核心首次降到 50 以下啟動兩回合「重整」倒數。倒數期間每回合都是
             * 可打斷的預告，需要兩次「不同系」的擾序才會中止；中止成功給全系破綻。
             */
            'overhaul' => [
                'trigger_core' => 50,
                'countdown_turns' => 2,
                'repair_magnitude' => 45,
                'required_interrupts' => 2,
            ],
            'intents' => [
                3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 18, 'interruptible' => true],
                5 => ['type' => 'shield', 'element' => 'land', 'magnitude' => 20, 'interruptible' => true],
                7 => ['type' => 'repair', 'element' => 'land', 'magnitude' => 20, 'interruptible' => true],
                10 => ['type' => 'shield', 'element' => 'water', 'magnitude' => 24, 'interruptible' => true],
                12 => ['type' => 'repair', 'element' => 'heat', 'magnitude' => 24, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'land', 'magnitude' => 8, 'interruptible' => false],
            'phases' => ['standby', 'overhaul'],
        ],
    ],
];
