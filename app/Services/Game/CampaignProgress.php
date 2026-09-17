<?php

namespace App\Services\Game;

use App\Domain\Game\LevelRepository;
use App\Models\Campaign;

/**
 * 戰役層級的進度：里程碑、牌組獎勵與兩種終幕。
 *
 * 第 3 關通關就完成主線目標，玩家可以「收下戰果，結束本次計畫」——那是結局，
 * 不是失敗，也不是少拿一個結局；收手之後仍然可以回來挑戰進階，進階失敗也不會
 * 撤銷主線通關（P04-REVISION-PLAN §2）。
 */
class CampaignProgress
{
    public const MAIN_CLEARED = 'main_cleared';

    public const STOOD_DOWN = 'stood_down';

    public const ADVANCED_CLEARED = 'advanced_cleared';

    public function __construct(
        private readonly LevelRepository $levels,
        private readonly DeckComposer $decks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(?Campaign $campaign): array
    {
        $firstLevel = $this->levels->all()[array_key_first($this->levels->all())];

        if ($campaign === null) {
            return $this->finales() + [
                'milestones' => [],
                'titles' => [],
                'deck' => $firstLevel->deck,
                'deck_choices' => [],
                'pending_reward' => null,
                'main_cleared' => false,
                'stood_down' => false,
                'advanced_cleared' => false,
            ];
        }

        $milestones = $campaign->milestones();
        $titles = (array) config('game.campaign.titles');

        return $this->finales() + [
            'milestones' => $milestones,
            'titles' => array_intersect_key($titles, array_flip($milestones)),
            'deck' => $this->decks->compose($firstLevel, $campaign),
            'deck_choices' => $campaign->deckChoices(),
            'pending_reward' => $campaign->pending_reward === null
                ? null
                : $this->decks->rewardOffer($campaign->pending_reward, $campaign),
            'main_cleared' => in_array(self::MAIN_CLEARED, $milestones, true),
            'stood_down' => in_array(self::STOOD_DOWN, $milestones, true),
            'advanced_cleared' => in_array(self::ADVANCED_CLEARED, $milestones, true),
        ];
    }

    /**
     * 兩個終幕的關卡代碼。前端據此決定通關後要不要進終幕，不在畫面上硬寫關卡 ID。
     *
     * @return array<string, string>
     */
    private function finales(): array
    {
        return [
            'main_finale' => (string) config('game.campaign.main_finale'),
            'advanced_finale' => (string) config('game.campaign.advanced_finale'),
        ];
    }

    /**
     * 挑一張獎勵牌。同一關只能挑一次——這是策略分支，不是可以反覆刷的商店。
     */
    public function chooseReward(Campaign $campaign, string $levelId, string $optionKey): void
    {
        $choices = $campaign->deckChoices();

        $campaign->forceFill([
            'deck_choices' => $choices + [$levelId => $optionKey],
            'pending_reward' => $campaign->pending_reward === $levelId ? null : $campaign->pending_reward,
        ])->save();
    }

    /**
     * 收下戰果，結束本次毀滅計畫。進階關卡仍然留著，日後可以從同一份存檔挑戰。
     */
    public function standDown(Campaign $campaign): void
    {
        if ($campaign->hasMilestone(self::STOOD_DOWN)) {
            return;
        }

        $campaign->forceFill([
            'milestones' => [...$campaign->milestones(), self::STOOD_DOWN],
        ])->save();
    }
}
