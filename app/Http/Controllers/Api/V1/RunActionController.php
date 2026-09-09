<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ActionResource;
use App\Services\Game\CampaignResolver;
use App\Services\Game\Exceptions\RunConflictException;
use App\Services\Game\RunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RunActionController extends Controller
{
    public function store(
        Request $request,
        string $run,
        RunService $runs,
        CampaignResolver $campaigns,
    ): JsonResponse {
        $validated = $request->validate([
            'action_id' => ['required', 'string', 'max:64'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in(array_column(ActionType::cases(), 'value'))],
            'card_id' => ['nullable', 'string', 'max:64'],
            'fixed' => ['nullable', 'string', Rule::in(config('game.fixed_actions'))],
            'keep' => ['array', 'max:5'],
            'keep.*' => ['string', 'max:64'],
        ], [
            'action_id.required' => '缺少 action_id',
            'action_id.string' => 'action_id 格式錯誤',
            'action_id.max' => 'action_id 長度不可超過 64 字',
            'expected_version.required' => '缺少 expected_version',
            'expected_version.integer' => 'expected_version 必須是整數',
            'expected_version.min' => 'expected_version 必須大於等於 1',
            'type.required' => '缺少行動類型',
            'type.in' => '這個行動類型不存在',
            'card_id.string' => 'card_id 格式錯誤',
            'card_id.max' => 'card_id 長度不可超過 64 字',
            'fixed.in' => '這不是手牌旁的固定行動',
            'keep.array' => 'keep 必須是陣列',
            'keep.max' => '留牌張數超過手牌上限',
            'keep.*.string' => 'keep 只能是牌的識別碼',
        ]);

        $model = RunController::ownedRun($campaigns->existing($request), $run);

        $action = new ActionRequest(
            actionId: $validated['action_id'],
            expectedVersion: $validated['expected_version'],
            type: ActionType::from($validated['type']),
            cardId: $validated['card_id'] ?? null,
            fixedSkillId: $validated['fixed'] ?? null,
            keep: $validated['keep'] ?? [],
        );

        try {
            $outcome = $runs->submit($model, $action);
        } catch (RunConflictException $exception) {
            // 409：取得現況再決策。不消耗回合，也不改變任何資源。
            return response()->json([
                'reason_code' => $exception->reasonCode,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
                'current_version' => $model->fresh()->version,
                'server_time' => Carbon::now()->toIso8601ZuluString('millisecond'),
            ], 409);
        } catch (InvalidActionException $exception) {
            // 422：規則不合法，交易沒有寫入，回合與惡意都沒有被消耗。
            return response()->json([
                'reason_code' => $exception->reasonCode,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
                'current_version' => $model->fresh()->version,
                'server_time' => Carbon::now()->toIso8601ZuluString('millisecond'),
            ], 422);
        }

        /*
         * 一律 200。Laravel 預設會對「剛建立的模型 + POST」回 201，但那會讓
         * 首次提交與重送回不同狀態碼，客戶端反而要分兩條路處理同一個結果。
         */
        return (new ActionResource($outcome['action'], $outcome['replayed']))
            ->response()
            ->setStatusCode(200);
    }
}
