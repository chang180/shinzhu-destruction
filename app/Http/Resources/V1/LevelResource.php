<?php

namespace App\Http\Resources\V1;

use App\Domain\Game\LevelDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LevelDefinition
 */
class LevelResource extends JsonResource
{
    public function __construct(
        LevelDefinition $resource,
        private readonly bool $unlocked,
        private readonly ?array $best,
        private readonly bool $practiceUnlocked = false,
        private readonly ?array $practiceBest = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'level_id' => $this->id,
            'sequence' => $this->sequence,
            'tier' => $this->tier,
            // available：這一關的內容做完了沒有。和 unlocked（玩家進度）無關。
            'available' => $this->available,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'apostle' => $this->apostle,
            'mechanic' => $this->mechanic,
            'lesson' => $this->resource->lesson,
            'briefing' => $this->available ? $this->resource->briefing : null,
            'reward' => $this->resource->reward === null ? null : ['options' => array_keys($this->resource->reward['options'])],
            'max_turns' => $this->maxTurns,
            'requires' => $this->requires,
            'initial_defenses' => $this->defenses,
            'data_elements' => $this->dataElements,
            'deck' => $this->deck,
            'deck_size' => $this->resource->deckSize(),
            'unlocked' => $this->unlocked,
            'best' => $this->best,
            'practice_unlocked' => $this->practiceUnlocked,
            'practice_best' => $this->practiceBest,
            // 關卡幕次的名稱、目標、順序與可公開的進入條件（P10-1）；預告表本身仍走 forecast。
            'phases' => $this->resource->publicPhases(),
            // 預告表只在內容已交付的關卡送出，避免把未驗證的數值當成正式難度公告。
            // 多幕關卡每回合取該回合所在幕的預告表，並標明幕次（P10-2）。
            'forecast' => $this->available
                ? array_map(function (int $turn): array {
                    $phase = $this->resource->scheduledPhaseForTurn($turn);

                    return $this->resource->intentForTurn($turn, null, $phase->id)->toArray() + ['level_phase_id' => $phase->id];
                }, range(1, $this->maxTurns))
                : [],
        ];
    }
}
