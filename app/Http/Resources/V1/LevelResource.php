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
            // 預告表只在內容已交付的關卡送出，避免把未驗證的數值當成正式難度公告。
            'forecast' => $this->available
                ? array_map(fn (int $turn): array => $this->resource->intentForTurn($turn)->toArray(), range(1, $this->maxTurns))
                : [],
        ];
    }
}
