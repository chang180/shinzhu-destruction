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

    'rules_version' => '3.0.0',

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

        /*
         * P05 牌組獎勵的四張新牌。它們只是既有技能的另一種包裝——成本、冷卻與
         * 衝擊仍然從技能表讀，所以獎勵改變的是牌組結構（多一張破陣還是多一張擾序），
         * 不是給玩家一組更大的數字。
         */
        'tide-siege' => [
            'skill' => 'breach.water',
            'name' => '潮圍夜巷',
            'text' => '深夜把整排水管接出來灌進巷子，讓供水防線一次撐破。',
            'role' => '水系破陣：用一張試探的位置換一次爆發。',
        ],
        'smoke-screen' => [
            'skill' => 'disrupt.heat',
            'name' => '煙幕遮巡',
            'text' => '趁巡檢時間點火造煙，讓城市連自己哪裡在發燙都看不見。',
            'role' => '熱系擾序：用一張試探的位置換一次打斷。',
        ],
        'hollow-ground' => [
            'skill' => 'breach.land',
            'name' => '掏空地基',
            'text' => '偷挖砂石把路基掏空，讓地面自己塌下去。',
            'role' => '土地系破陣：用一張試探的位置換一次爆發。',
        ],
        'sluice-jam' => [
            'skill' => 'disrupt.water',
            'name' => '閘門卡死',
            'text' => '把雜物塞進閘門，逼城市先回頭救自己的水門。',
            'role' => '水系擾序：用一張試探的位置換一次打斷。',
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

    'campaign' => [
        // 主線最後一關通關＝完成主線目標，可以收手結束；進階最後一關通關＝進階終幕。
        'main_finale' => 'meter-feast',
        'advanced_finale' => 'stored-night',
        'titles' => [
            'main_cleared' => '毀滅計畫通過',
            'advanced_cleared' => '首席反派',
        ],
    ],

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
            'lesson' => '現在出破陣，或留擾序等修復窗口。',
            'briefing' => [
                'headline' => "讓城市\n喊渴。",
                'quote' => '「杯子空了，補水就好。城市空了呢？」',
                'quote_note' => '晏沉將空杯推到你面前。「這就是你今天的作業。」',
                'lessons' => [
                    '讀預告。第 3、6 回合城市會修復核心；同系擾序牌可以取消它，首次打斷還會返還 2 點惡意。',
                ],
            ],
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
            'available' => true,
            'name' => '折晝・竹北長晝',
            'subtitle' => '在系別間輪替的護盾',
            'apostle' => 'noon-fold',
            'max_turns' => 9,
            'requires' => 'empty-cup',
            'mechanic' => '護盾在系別之間輪替：留強牌等窗口，或換系繞過盾。',
            'lesson' => '留強牌等護盾退場，或直接換一系繞過去。',
            'briefing' => [
                'headline' => "折斷\n這一天。",
                'quote' => '「街區學會了輪班守夜。哪一系被盯上，那一系就擋得住。」',
                'quote_note' => '晏沉把三張排班表攤開。「所以你要嘛等它換班，要嘛去打它沒排班的那一邊。」',
                'lessons' => [
                    '護盾會輪替。第 2、4、6、8 回合城市各架起一道不同系的護盾，護盾同時只會有一道，兩回合後退場。',
                    '護盾吸收的是核心衝擊，不是防線。擋不過就換系打，或用同系擾序直接取消那道預告。',
                ],
            ],
            'defenses' => [
                Element::Water->value => 36,
                Element::Heat->value => 34,
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
                3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 20, 'interruptible' => true],
                4 => ['type' => 'shield', 'element' => 'water', 'magnitude' => 16, 'interruptible' => true],
                6 => ['type' => 'shield', 'element' => 'land', 'magnitude' => 18, 'interruptible' => true],
                7 => ['type' => 'repair', 'element' => 'land', 'magnitude' => 22, 'interruptible' => true],
                8 => ['type' => 'shield', 'element' => 'heat', 'magnitude' => 18, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'heat', 'magnitude' => 4, 'interruptible' => false],
            'reward' => [
                'prompt' => '折晝結束。晏沉讓你把一張試探換成一張真正的手段——只能挑一張，牌組仍然是 15 張。',
                'options' => [
                    'tide-siege' => ['add' => 'tide-siege', 'remove' => 'long-flow', 'style' => '爆發'],
                    'smoke-screen' => ['add' => 'smoke-screen', 'remove' => 'open-chill', 'style' => '干擾'],
                ],
            ],
            'phases' => [],
        ],

        'meter-feast' => [
            'sequence' => 3,
            'tier' => 'main',
            'available' => true,
            'name' => '饗表・園區無底帳',
            'subtitle' => '預告的需求脈衝',
            'apostle' => 'meter-feast',
            'max_turns' => 10,
            'requires' => 'noon-fold',
            'mechanic' => '預告的需求脈衝：修復與進攻窗口互相排擠，得為關鍵回合留牌。',
            'lesson' => '為脈衝回合留牌、留惡意，並決定何時交出終招。',
            'briefing' => [
                'headline' => "把帳\n結乾淨。",
                'quote' => '「園區的表從不回頭看。它只問下一筆要多少。」',
                'quote_note' => '晏沉指著跳動的數字。「這是你的畢業考——主線的最後一關。」',
                'lessons' => [
                    '需求脈衝在第 4、8 回合。那兩回合完成跨系連攜會多返還 1 點惡意。',
                    '城市的修復與護盾輪流出現，回合又只有 10 個。想清楚哪一回合留牌、哪一回合放終招。',
                    '通關即完成主線目標。你可以收下戰果結束計畫，也可以接受進階畢業考。',
                ],
            ],
            'defenses' => [
                Element::Water->value => 24,
                Element::Heat->value => 24,
                Element::Land->value => 22,
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
                2 => ['type' => 'shield', 'element' => 'water', 'magnitude' => 16, 'interruptible' => true],
                3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 18, 'interruptible' => true],
                5 => ['type' => 'shield', 'element' => 'heat', 'magnitude' => 16, 'interruptible' => true],
                6 => ['type' => 'repair', 'element' => 'land', 'magnitude' => 18, 'interruptible' => true],
                7 => ['type' => 'repair', 'element' => 'heat', 'magnitude' => 20, 'interruptible' => true],
                9 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 22, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'land', 'magnitude' => 4, 'interruptible' => false],
            'phases' => [],
        ],

        'mirror-shade' => [
            'sequence' => 4,
            'tier' => 'advanced',
            'available' => true,
            'name' => '鏡蔭・丘陵借影',
            'subtitle' => '城市開始讀你的牌',
            'apostle' => 'mirror-shade',
            'max_turns' => 10,
            'requires' => 'meter-feast',
            'mechanic' => '城市依你最近出牌的系別選下一回合的護盾：誘出反制再換系。',
            'lesson' => '誘出城市的反制，再換系打它沒防到的那一邊。',
            'briefing' => [
                'headline' => "它在\n看你出牌。",
                'quote' => '「丘陵會借影子。你前一手打哪一系，它下一手就擋哪一系。」',
                'quote_note' => '晏沉在鏡面上寫下你的名字，隨即擦掉。「進階考不撤銷你的主線通過。放心輸。」',
                'lessons' => [
                    '沒有排定行程的回合，城市會鏡射你上一次進攻的系別，架起同系護盾——預告上會直接寫是哪一系。',
                    '這代表固定牌序會被反制：先用便宜的試探把它的盾引到一系，下一手換另一系打。',
                    '第 3、6、9 回合是排定的修復，鏡射不生效；那幾回合仍然可以用同系擾序打斷。',
                ],
            ],
            'defenses' => [
                Element::Water->value => 40,
                Element::Heat->value => 38,
                Element::Land->value => 38,
            ],
            'data_elements' => [Element::Heat->value, Element::Land->value],
            'deck' => [
                'long-flow' => 3, 'spend-tide' => 1, 'foul-current' => 1,
                'open-chill' => 3, 'hundred-smoke' => 1, 'idle-fume' => 1,
                'trample-green' => 3, 'no-green-left' => 1, 'filth-spread' => 1,
            ],
            'apostle_power' => 'interrupt_refund',
            'apostle_power_value' => 2,
            'adaptive_shield' => [
                'magnitude' => 20,
                'from_turn' => 2,
            ],
            'intents' => [
                3 => ['type' => 'repair', 'element' => 'land', 'magnitude' => 18, 'interruptible' => true],
                6 => ['type' => 'repair', 'element' => 'heat', 'magnitude' => 20, 'interruptible' => true],
                9 => ['type' => 'repair', 'element' => 'land', 'magnitude' => 22, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'shield', 'element' => 'land', 'magnitude' => 12, 'interruptible' => true],
            'reward' => [
                'prompt' => '鏡蔭讀不到的那一手，晏沉替你留了兩張。挑一張換進牌組，準備最後一關。',
                'options' => [
                    'hollow-ground' => ['add' => 'hollow-ground', 'remove' => 'trample-green', 'style' => '爆發'],
                    'sluice-jam' => ['add' => 'sluice-jam', 'remove' => 'long-flow', 'style' => '干擾'],
                ],
            ],
            'phases' => [],
        ],

        'stored-night' => [
            'sequence' => 5,
            'tier' => 'advanced',
            'available' => true,
            'name' => '蓄夜・全縣最後重整',
            'subtitle' => '兩回合重整',
            'apostle' => 'stored-night',
            'max_turns' => 12,
            'requires' => 'mirror-shade',
            'mechanic' => '核心到門檻就啟動兩回合重整：兩次不同系干擾中止，或搶先結束。',
            'lesson' => '以兩次不同系干擾中止重整，或集中輸出搶先結束。',
            'briefing' => [
                'headline' => "在它\n重整之前。",
                'quote' => '「全縣會把最後的力氣留到夜裡，一次修回來。」',
                'quote_note' => '晏沉熄掉最後一盞燈。「你只有兩個選擇：打斷它，或比它快。」',
                'lessons' => [
                    '核心降到 50 以下，城市啟動兩回合重整；完成就一次回復 34 點核心韌性。',
                    '用兩種不同系的擾序打斷重整倒數即可中止，並換來全系破綻；或者在倒數結束前把核心打完。',
                    '通關取得「首席反派」與進階終幕。失敗不會撤銷主線通關。',
                ],
            ],
            'defenses' => [
                Element::Water->value => 32,
                Element::Heat->value => 30,
                Element::Land->value => 32,
            ],
            'data_elements' => [Element::Water->value, Element::Heat->value, Element::Land->value],
            // 三系同時採用：每系的情境修正再夾一次，避免「資料好壞」本身決定勝負。
            'modifier_cap' => 0.08,
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
                'repair_magnitude' => 48,
                'required_interrupts' => 2,
            ],
            'intents' => [
                2 => ['type' => 'shield', 'element' => 'water', 'magnitude' => 18, 'interruptible' => true],
                3 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 18, 'interruptible' => true],
                5 => ['type' => 'shield', 'element' => 'land', 'magnitude' => 18, 'interruptible' => true],
                6 => ['type' => 'repair', 'element' => 'land', 'magnitude' => 20, 'interruptible' => true],
                8 => ['type' => 'repair', 'element' => 'heat', 'magnitude' => 22, 'interruptible' => true],
                10 => ['type' => 'shield', 'element' => 'heat', 'magnitude' => 20, 'interruptible' => true],
                11 => ['type' => 'repair', 'element' => 'water', 'magnitude' => 24, 'interruptible' => true],
            ],
            'default_intent' => ['type' => 'reinforce', 'element' => 'land', 'magnitude' => 5, 'interruptible' => false],
            'phases' => ['standby', 'overhaul'],
        ],
    ],
];
