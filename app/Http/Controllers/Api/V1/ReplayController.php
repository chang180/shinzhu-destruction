<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RunAction;
use App\Services\Game\CampaignResolver;
use App\Services\OpenData\SnapshotRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReplayController extends Controller
{
    /**
     * 已結束對局的重播資料：規則版本、凍結的快照、情境修正、完整行動與事件。
     *
     * 這是 P07 複盤與反事實比較的唯一輸入；沒有這些，任何「若第 6 回合改用干擾」
     * 的說法都只是猜測。
     */
    public function show(
        Request $request,
        string $run,
        CampaignResolver $campaigns,
        SnapshotRepository $snapshots,
    ): JsonResponse {
        $campaign = $campaigns->resolve($request);
        $model = RunController::ownedRun($campaign, $run);

        if (! $model->outcome->isFinished()) {
            return response()->json([
                'reason_code' => 'run_in_progress',
                'message' => '這一局還沒結束，重播要等結算之後',
            ], 409);
        }

        $frozen = [];

        foreach ($model->snapshot_ids as $sourceId => $snapshotId) {
            $snapshot = $snapshots->find($snapshotId);

            $frozen[$sourceId] = [
                'snapshot_id' => $snapshotId,
                // 快照可能已被清理；明說「已不可得」，不用目前的快照冒充當時的。
                'available' => $snapshot !== null,
                'quality' => $snapshot?->quality->value,
                'observed_at' => $snapshot?->payload['observed_at'] ?? null,
                'period' => $snapshot?->payload['period'] ?? null,
            ];
        }

        return response()->json([
            'run_id' => $model->public_id,
            'level_id' => $model->level_id,
            'rules_version' => $model->rules_version,
            'seed' => (int) $model->seed,
            'outcome' => $model->outcome->value,
            'scenario' => $model->scenario_modifiers,
            'snapshots' => $frozen,
            'final_state' => $model->state,
            'actions' => $model->actions->map(static fn (RunAction $action): array => [
                'sequence' => $action->sequence,
                'action_id' => $action->action_id,
                'input' => $action->input,
                'events' => $action->events,
                'version_after' => $action->version_after,
            ])->all(),
        ]);
    }
}
