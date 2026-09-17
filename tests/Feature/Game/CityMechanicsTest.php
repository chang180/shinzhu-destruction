<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleState;
use App\Domain\Game\Element;
use App\Domain\Game\Scenario\ScenarioModifiers;
use Tests\TestCase;

/**
 * P05 的城市機制：同系護盾、鏡射護盾與逐關情境修正上限。
 *
 * 這些是 3.0.0 新增或改寫的規則，直接決定第 2、4、5 關的打法，所以每一條都
 * 用引擎實際結算驗證，不是看設定檔有沒有那個欄位。
 */
class CityMechanicsTest extends TestCase
{
    use PlaysCards;

    public function test_a_shield_only_absorbs_attacks_of_its_own_element(): void
    {
        $level = $this->level('noon-fold');
        $state = $this->startState($level);
        $state->shields = [['element' => Element::Water->value, 'amount' => 30, 'expires_on_turn' => 99]];

        [$blocked] = $this->act($state, 'probe.water', $level);
        [$around] = $this->act($state, 'probe.heat', $level);

        // 同系被吃掉，換一系就繞過去了——這是第 2 關「換系繞盾」成立的前提。
        $this->assertSame(100, $blocked->coreResilience);
        $this->assertLessThan(100, $around->coreResilience);
    }

    public function test_the_ultimate_is_absorbed_by_any_shield_because_it_is_a_three_element_strike(): void
    {
        $level = $this->level('noon-fold');
        $state = $this->startState($level);
        $state->sigils = array_fill_keys(Element::values(), 3);
        $state->malice = 10;

        [$unshielded] = $this->act($state, 'ultimate', $level);

        $shielded = $state->copy();
        $shielded->shields = [['element' => Element::Land->value, 'amount' => 20, 'expires_on_turn' => 99]];
        [$absorbed] = $this->act($shielded, 'ultimate', $level);

        $this->assertGreaterThan($unshielded->coreResilience, $absorbed->coreResilience);
    }

    public function test_the_mirror_level_shields_the_element_you_just_attacked_with(): void
    {
        $level = $this->level('mirror-shade');
        $state = $this->startState($level);

        [$afterHeat] = $this->act($state, 'probe.heat', $level);

        $this->assertNotNull($afterHeat->intent);
        $this->assertSame('shield', $afterHeat->intent->type);
        $this->assertSame(Element::Heat, $afterHeat->intent->element);
        $this->assertTrue($afterHeat->intent->interruptible);
        $this->assertStringContainsString('熱', $afterHeat->intent->description);

        [$afterLand] = $this->act($state, 'probe.land', $level);

        $this->assertSame(Element::Land, $afterLand->intent->element);
    }

    public function test_a_scheduled_intent_still_wins_over_the_mirror_on_the_turns_it_covers(): void
    {
        $level = $this->level('mirror-shade');

        // 第 3 回合是排定的土地系修復；它不該被第 2 回合的出牌換掉。
        $this->assertTrue($level->hasScheduledIntent(3));
        $this->assertFalse($level->mirrorsPlayerOnTurn(3));

        $intent = $level->intentForTurn(3, Element::Water);

        $this->assertSame('repair', $intent->type);
        $this->assertSame(Element::Land, $intent->element);
    }

    public function test_the_static_forecast_says_the_shield_mirrors_the_player_instead_of_naming_a_fixed_element(): void
    {
        $level = $this->level('mirror-shade');
        $intent = $level->intentForTurn(4);

        // 還沒有人出手時不能假裝城市已經選好系別。
        $this->assertStringContainsString('鏡射', $intent->description);
    }

    public function test_the_final_level_caps_each_element_modifier_so_data_alone_cannot_decide_the_run(): void
    {
        $level = $this->level('stored-night');

        $this->assertSame(0.08, $level->modifierCap);
        $this->assertSame(0.08, $level->cappedModifier(0.15));
        $this->assertSame(-0.08, $level->cappedModifier(-0.15));
        $this->assertSame(0.04, $level->cappedModifier(0.04));

        // 沒有設上限的關卡照原值，不會被這條規則悄悄改掉。
        $this->assertSame(0.15, $this->level('empty-cup')->cappedModifier(0.15));
    }

    public function test_the_capped_level_deals_the_same_damage_at_the_cap_and_beyond_it(): void
    {
        $level = $this->level('stored-night');
        $state = $this->startState($level);

        $atCap = $this->engine()->apply(
            $this->reveal($this->stack($state, 'probe.water'), $level),
            $this->playRequest($this->reveal($this->stack($state, 'probe.water'), $level), 'probe.water'),
            $level,
            $this->uniformModifiers(0.08),
        )->state;

        $beyondCap = $this->engine()->apply(
            $this->reveal($this->stack($state, 'probe.water'), $level),
            $this->playRequest($this->reveal($this->stack($state, 'probe.water'), $level), 'probe.water'),
            $level,
            $this->uniformModifiers(0.15),
        )->state;

        $this->assertSame($atCap->coreResilience, $beyondCap->coreResilience);
    }

    private function uniformModifiers(float $value): ScenarioModifiers
    {
        $modifiers = [];
        $reasons = [];

        foreach (Element::all() as $element) {
            $modifiers[$element->value] = $value;
            $reasons[$element->value] = ['code' => 'test', 'message' => '', 'inputs' => []];
        }

        return new ScenarioModifiers($modifiers, $reasons);
    }

    private function playRequest(BattleState $state, string $skillId): ActionRequest
    {
        return new ActionRequest(
            actionId: 'play-'.$state->version,
            expectedVersion: $state->version,
            type: ActionType::Play,
            cardId: $this->instanceFor($state, $skillId),
        );
    }
}
