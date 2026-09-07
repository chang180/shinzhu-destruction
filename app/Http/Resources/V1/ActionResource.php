<?php

namespace App\Http\Resources\V1;

use App\Models\RunAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 一次成功行動的回應：run_id、action_id、version、state 與有序 events
 * （TECHNICAL-SPEC §6）。
 *
 * @mixin RunAction
 */
class ActionResource extends JsonResource
{
    public function __construct(RunAction $resource, private readonly bool $replayed)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'run_id' => $this->run->public_id,
            'action_id' => $this->action_id,
            'sequence' => $this->sequence,
            'version' => $this->version_after,
            'replayed' => $this->replayed,
            'state' => $this->state_after,
            'events' => $this->events,
        ];
    }
}
