<?php

namespace App\Http\Resources\V1;

use App\Domain\Game\BattleEngine;
use App\Models\Run;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Run
 */
class RunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BattleEngine $engine */
        $engine = app(BattleEngine::class);
        $state = $this->battleState();

        return [
            'run_id' => $this->public_id,
            'level_id' => $this->level_id,
            'rules_version' => $this->rules_version,
            'version' => $this->version,
            'outcome' => $this->outcome->value,
            'state' => $state->toArray(),
            'compatible' => $this->rules_version === $engine->rulesVersion(),
            'available_actions' => $this->rules_version === $engine->rulesVersion() ? $engine->availableActions($state) : [],
            'snapshots' => $this->snapshot_metadata ?? [],
            'history' => $this->actions->map(static fn ($action): array => [
                'sequence' => $action->sequence,
                'input' => $action->input,
                'events' => $action->events,
            ])->all(),
            // 去秘密化：只送情境修正與推導理由，不送內部快照路徑或錯誤細節。
            'scenario' => $this->scenario_modifiers,
            'snapshot_ids' => $this->snapshot_ids,
        ];
    }
}
