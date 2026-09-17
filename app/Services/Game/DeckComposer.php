<?php

namespace App\Services\Game;

use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\LevelRepository;
use App\Models\Campaign;

/**
 * 把「戰役層級的牌組獎勵」換算成某一關實際要用的牌組。
 *
 * 獎勵是替換不是增牌：選了一張新牌就少一張原本的試探，牌組張數不變
 * （P04-REVISION-PLAN §4.6）。獎勵只改牌組結構——多一張破陣或多一張擾序——
 * 成本與衝擊仍然來自技能表，所以它是策略分支，不是永久增傷。
 */
class DeckComposer
{
    public function __construct(
        private readonly LevelRepository $levels,
        private readonly CardCatalog $cards,
    ) {}

    /**
     * @return array<string, int> 牌型 => 張數
     */
    public function compose(LevelDefinition $level, ?Campaign $campaign): array
    {
        $deck = $level->deck;

        if ($campaign === null) {
            return $deck;
        }

        foreach ($this->levels->all() as $levelId => $candidate) {
            $option = $this->chosenOption($candidate, $campaign->deckChoices()[$levelId] ?? null);

            if ($option === null) {
                continue;
            }

            $deck = $this->applyOption($deck, $option);
        }

        return $deck;
    }

    /**
     * 這一關通關後要不要讓玩家挑牌：有獎勵設定、而且還沒挑過。
     */
    public function hasPendingReward(LevelDefinition $level, Campaign $campaign): bool
    {
        return $level->reward !== null && ! array_key_exists($level->id, $campaign->deckChoices());
    }

    /**
     * 獎勵的兩個選項，附上實際會加入與移除的卡面，讓玩家挑之前就看得到代價。
     *
     * @return array<string, mixed>|null
     */
    public function rewardOffer(string $levelId, Campaign $campaign): ?array
    {
        $level = $this->levels->get($levelId);

        if ($level->reward === null) {
            return null;
        }

        $deck = $this->compose($level, $campaign);
        $options = [];

        foreach ($level->reward['options'] as $key => $option) {
            $options[] = [
                'key' => $key,
                'style' => $option['style'],
                'add' => $this->cards->get($option['add'])->toArray($this->cards->skillFor($option['add'])),
                'remove' => $this->cards->get($option['remove'])->toArray($this->cards->skillFor($option['remove'])),
                'remove_remaining' => max(0, ($deck[$option['remove']] ?? 0) - 1),
            ];
        }

        return [
            'level_id' => $levelId,
            'level_name' => $level->name,
            'prompt' => $level->reward['prompt'],
            'options' => $options,
            'chosen' => $campaign->deckChoices()[$levelId] ?? null,
        ];
    }

    /**
     * 選項是否真的可以套用。牌組裡已經沒有那張要被換掉的牌時就不行——
     * 那會讓牌組少一張，不是「替換」。
     */
    public function canApply(LevelDefinition $level, Campaign $campaign, string $optionKey): bool
    {
        $option = $level->reward['options'][$optionKey] ?? null;

        if ($option === null) {
            return false;
        }

        return ($this->compose($level, $campaign)[$option['remove']] ?? 0) > 0;
    }

    /**
     * @param  array<string, int>  $deck
     * @param  array<string, string>  $option
     * @return array<string, int>
     */
    private function applyOption(array $deck, array $option): array
    {
        if (($deck[$option['remove']] ?? 0) <= 0) {
            return $deck;
        }

        $deck[$option['remove']]--;
        $deck[$option['add']] = ($deck[$option['add']] ?? 0) + 1;

        if ($deck[$option['remove']] === 0) {
            unset($deck[$option['remove']]);
        }

        return $deck;
    }

    /**
     * @return array<string, string>|null
     */
    private function chosenOption(LevelDefinition $level, ?string $key): ?array
    {
        if ($key === null || $level->reward === null) {
            return null;
        }

        return $level->reward['options'][$key] ?? null;
    }
}
