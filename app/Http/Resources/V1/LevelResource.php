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
    public function __construct(LevelDefinition $resource, private readonly bool $unlocked, private readonly ?array $best)
    {
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
            'name' => $this->name,
            'apostle' => $this->apostle,
            'max_turns' => $this->maxTurns,
            'requires' => $this->requires,
            'initial_defenses' => $this->defenses,
            'data_elements' => $this->dataElements,
            'unlocked' => $this->unlocked,
            'best' => $this->best,
            'forecast' => array_map(fn (int $turn): array => $this->resource->intentForTurn($turn)->toArray(), range(1, $this->maxTurns)),
        ];
    }
}
