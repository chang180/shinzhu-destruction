<?php

namespace App\Http\Resources\V1;

use App\Domain\Game\BattleState;
use App\Models\RunAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * 一次成功行動的回應：run_id、action_id、version、state 與有序 events
 * （TECHNICAL-SPEC §6）。
 *
 * `state_after` 是含抽牌堆順序的完整存檔，所以這裡一定要轉成對外局面；
 * 直接送出等於把未抽牌序交給客戶端。
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
            'state' => BattleState::fromArray($this->state_after)->toPublicArray(),
            'events' => $this->events,
            // 倒數由伺服器截止時間判定，客戶端要用這個時間對齊自己的時鐘。
            'server_time' => Carbon::now()->toIso8601ZuluString('millisecond'),
        ];
    }
}
