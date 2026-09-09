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

    'rules_version' => '2.0.0',

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
    | 手牌與決策窗口
    |--------------------------------------------------------------------------
    |
    | 每回合手牌 5 張、出 1 張；最多留 2 張到下回合，另有每回合一次的免費換牌。
    | 蓄勢與終招不是牌，是手牌旁的固定行動——否則勝負會取決於有沒有抽到終招。
    |
    */

    'hand' => [
        'size' => 5,
        'max_keep' => 2,
        'swaps_per_turn' => 1,
    ],

    'fixed_actions' => ['gather', 'ultimate'],

    'timer' => [
        // 挑戰模式的決策窗口。練習模式不設截止時間，其餘規則完全相同。
        'decision_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | 牌型
    |--------------------------------------------------------------------------
    |
    | 卡名是反派禁術包裝，卡面說明直接交代那個日常環境壞行為。規則計算只認
    | `skill`，所以換名字不會改動平衡。資料連結由本局實際生效的情境修正決定，
    | 不在這裡硬寫成「今天居民浪費多少水」之類的假統計。
    |
    */

    'cards' => [

        'long-flow' => [
            'skill' => 'probe.water',
            'name' => '千戶長流',
            'text' => '鼓吹模擬居民隨意用水，讓水龍頭整日長流不關。',
            'role' => '水系試探：低成本施壓，累積水系印記。',
        ],
        'spend-tide' => [
            'skill' => 'breach.water',
            'name' => '揮霍成潮',
            'text' => '把浪費用水的風氣推向高峰，壓迫城市的供水防線。',
            'role' => '水系破陣：高成本換取防線破口。',
        ],
        'foul-current' => [
            'skill' => 'disrupt.water',
            'name' => '濁流塞管',
            'text' => '把髒東西順手倒進水溝，逼城市停下手邊工作先去通管。',
            'role' => '水系擾序：打斷可打斷的水系城市行動。',
        ],

        'open-chill' => [
            'skill' => 'probe.heat',
            'name' => '開窗吹冷',
            'text' => '慫恿模擬住戶開著窗吹冷氣，熱氣整天往街上排。',
            'role' => '熱系試探：低成本施壓，累積熱系印記。',
        ],
        'hundred-smoke' => [
            'skill' => 'breach.heat',
            'name' => '百巷烏煙',
            'text' => '煽動模擬街區亂燒垃圾，讓煙與熱籠罩城市。',
            'role' => '熱系破陣：原創卡牌效果，沒有空污或排放量加成。',
        ],
        'idle-fume' => [
            'skill' => 'disrupt.heat',
            'name' => '怠速成霾',
            'text' => '讓車輛原地怠速排煙，煙塵遮住城市的巡檢視線。',
            'role' => '熱系擾序：打斷可打斷的熱系城市行動。',
        ],

        'trample-green' => [
            'skill' => 'probe.land',
            'name' => '踐草成徑',
            'text' => '帶頭抄捷徑踩踏草地，把綠帶踩成一條條裸土。',
            'role' => '土地系試探：低成本施壓，累積土地系印記。',
        ],
        'no-green-left' => [
            'skill' => 'breach.land',
            'name' => '寸綠不留',
            'text' => '亂砍樹、鏟平綠地，讓城市失去綠色緩衝。',
            'role' => '土地系破陣：為後續進攻開出窗口。',
        ],
        'filth-spread' => [
            'skill' => 'disrupt.land',
            'name' => '穢物橫行',
            'text' => '讓垃圾四處堆積，迫使城市分心去清理。',
            'role' => '土地系擾序：打斷可打斷的土地系城市行動。',
        ],

        'hold-spite' => [
            'skill' => 'gather',
            'name' => '屏息蓄惡',
            'text' => '這一回合不出手，把惡意攢起來，順便鬆開最緊的那道適應。',
            'role' => '固定行動：回復惡意，中斷連攜。',
        ],
        'final-waste' => [
            'skill' => 'ultimate',
            'name' => '揮霍無度・新竹歸寂',
            'text' => '把本局累積的浪費與破壞一次推向終局。',
            'role' => '固定行動：消耗三系印記的終招。',
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
    | 戰役：3 關主線 ＋ 2 關進階
    |--------------------------------------------------------------------------
    |
    | 固定總量五關（P04-REVISION-PLAN §2）。13 鄉鎮市是資料涵蓋範圍，不是關卡數。
    | 關卡名稱是新竹縣意象下的虛構演習主題，不是對真實地區的脆弱度評比。
    |
    | `available` 是「這一關的內容做完了沒有」，和解鎖無關：P04 只交付第 1 關，
    | 第 2～5 關在這裡佔住穩定 ID、順序、依賴與唯一新增機制，數值與牌組待 P05
    | 依模擬結果訂定。回合數與防線是新版模擬的起點，不是已驗證的平衡值。
    |
    */

    'levels' => [

        'empty-cup' => [
            'sequence' => 1,
            'tier' => 'main',
            'available' => true,
            'name' => '枯潮・寶山空杯',
            'subtitle' => '讓城市喊渴',
            'apostle' => 'empty-cup',
            'max_turns' => 8,
            'requires' => null,
            'mechanic' => '固定、完整預告的修復窗口：城市什麼時候補血全部寫在預告上。',
            'defenses' => [
                Element::Water->value => 30,
                Element::Heat->value => 26,
                Element::Land->value => 26,
            ],
            'data_elements' => [Element::Water->value],
            'deck' => [
                'long-flow' => 3, 'spend-tide' => 1, 'foul-current' => 1,
                'open-chill' => 3, 'hundred-smoke' => 1, 'idle-fume' => 1,
                'trample-green' => 3, 'no-green-left' => 1, 'filth-spread' => 1,
            ],
            'apostle_power' => 'interrupt_refund',
            'apostle_power_value' => 2,
            'intents' => [
                3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 14, 'interruptible' => true],
                6 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 16, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'water', 'magnitude' => 4, 'interruptible' => false],
            'phases' => [],
        ],

        'noon-fold' => [
            'sequence' => 2,
            'tier' => 'main',
            'available' => false,
            'name' => '折晝・竹北長晝',
            'subtitle' => '在系別間輪替的護盾',
            'apostle' => 'noon-fold',
            'max_turns' => 8,
            'requires' => 'empty-cup',
            'mechanic' => '護盾在系別之間輪替：留強牌等窗口，或換系繞過盾。',
            'defenses' => [
                Element::Water->value => 36,
                Element::Heat->value => 36,
                Element::Land->value => 34,
            ],
            'data_elements' => [Element::Heat->value],
            'deck' => [
                'long-flow' => 3, 'spend-tide' => 1, 'foul-current' => 1,
                'open-chill' => 3, 'hundred-smoke' => 1, 'idle-fume' => 1,
                'trample-green' => 3, 'no-green-left' => 1, 'filth-spread' => 1,
            ],
            'apostle_power' => 'interrupt_refund',
            'apostle_power_value' => 2,
            'intents' => [
                2 => ['type' => 'shield', 'element' => 'heat', 'magnitude' => 16, 'interruptible' => true],
                4 => ['type' => 'shield', 'element' => 'water', 'magnitude' => 16, 'interruptible' => true],
                6 => ['type' => 'shield', 'element' => 'land', 'magnitude' => 18, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'heat', 'magnitude' => 5, 'interruptible' => false],
            'phases' => [],
        ],

        'meter-feast' => [
            'sequence' => 3,
            'tier' => 'main',
            'available' => false,
            'name' => '饗表・園區無底帳',
            'subtitle' => '預告的需求脈衝',
            'apostle' => 'meter-feast',
            'max_turns' => 10,
            'requires' => 'noon-fold',
            'mechanic' => '預告的需求脈衝：修復與進攻窗口互相排擠，得為關鍵回合留牌。',
            'defenses' => [
                Element::Water->value => 40,
                Element::Heat->value => 40,
                Element::Land->value => 38,
            ],
            'data_elements' => [Element::Water->value, Element::Heat->value],
            'deck' => [
                'long-flow' => 3, 'spend-tide' => 1, 'foul-current' => 1,
                'open-chill' => 3, 'hundred-smoke' => 1, 'idle-fume' => 1,
                'trample-green' => 3, 'no-green-left' => 1, 'filth-spread' => 1,
            ],
            'apostle_power' => 'pulse_combo_refund',
            'apostle_power_value' => 1,
            'pulse_turns' => [4, 8],
            'intents' => [
                3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 16, 'interruptible' => true],
                5 => ['type' => 'shield', 'element' => 'heat', 'magnitude' => 18, 'interruptible' => true],
                7 => ['type' => 'repair', 'element' => 'heat', 'magnitude' => 18, 'interruptible' => true],
                9 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 20, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'land', 'magnitude' => 5, 'interruptible' => false],
            'phases' => [],
        ],

        'mirror-shade' => [
            'sequence' => 4,
            'tier' => 'advanced',
            'available' => false,
            'name' => '鏡蔭・丘陵借影',
            'subtitle' => '城市開始讀你的牌',
            'apostle' => 'mirror-shade',
            'max_turns' => 10,
            'requires' => 'meter-feast',
            'mechanic' => '城市依你最近出牌的系別選下一回合的護盾：誘出反制再換系。',
            'defenses' => [
                Element::Water->value => 42,
                Element::Heat->value => 42,
                Element::Land->value => 44,
            ],
            'data_elements' => [Element::Heat->value, Element::Land->value],
            'deck' => [
                'long-flow' => 3, 'spend-tide' => 1, 'foul-current' => 1,
                'open-chill' => 3, 'hundred-smoke' => 1, 'idle-fume' => 1,
                'trample-green' => 3, 'no-green-left' => 1, 'filth-spread' => 1,
            ],
            'apostle_power' => 'interrupt_refund',
            'apostle_power_value' => 2,
            'intents' => [
                3 => ['type' => 'repair', 'element' => 'land', 'magnitude' => 18, 'interruptible' => true],
                6 => ['type' => 'repair', 'element' => 'heat', 'magnitude' => 20, 'interruptible' => true],
                9 => ['type' => 'repair', 'element' => 'land', 'magnitude' => 22, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'shield', 'element' => 'land', 'magnitude' => 14, 'interruptible' => true],
            'phases' => [],
        ],

        'stored-night' => [
            'sequence' => 5,
            'tier' => 'advanced',
            'available' => false,
            'name' => '蓄夜・全縣最後重整',
            'subtitle' => '兩回合重整',
            'apostle' => 'stored-night',
            'max_turns' => 12,
            'requires' => 'mirror-shade',
            'mechanic' => '核心到門檻就啟動兩回合重整：兩次不同系干擾中止，或搶先結束。',
            'defenses' => [
                Element::Water->value => 46,
                Element::Heat->value => 44,
                Element::Land->value => 46,
            ],
            'data_elements' => [Element::Water->value, Element::Heat->value, Element::Land->value],
            'deck' => [
                'long-flow' => 3, 'spend-tide' => 1, 'foul-current' => 1,
                'open-chill' => 3, 'hundred-smoke' => 1, 'idle-fume' => 1,
                'trample-green' => 3, 'no-green-left' => 1, 'filth-spread' => 1,
            ],
            'apostle_power' => 'overhaul_stop_breach',
            'apostle_power_value' => 1,
            'overhaul' => [
                'trigger_core' => 50,
                'countdown_turns' => 2,
                'repair_magnitude' => 40,
                'required_interrupts' => 2,
            ],
            'intents' => [
                3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 16, 'interruptible' => true],
                5 => ['type' => 'shield', 'element' => 'land', 'magnitude' => 18, 'interruptible' => true],
                8 => ['type' => 'repair', 'element' => 'heat', 'magnitude' => 20, 'interruptible' => true],
                11 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 22, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'land', 'magnitude' => 6, 'interruptible' => false],
            'phases' => ['standby', 'overhaul'],
        ],
    ],
];
