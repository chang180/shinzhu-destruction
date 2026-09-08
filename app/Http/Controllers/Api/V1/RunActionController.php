<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\Element;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\SkillCatalog;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ActionResource;
use App\Services\Game\CampaignResolver;
use App\Services\Game\Exceptions\RunConflictException;
use App\Services\Game\RunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RunActionController extends Controller
{
    public function store(
        Request $request,
        string $run,
        RunService $runs,
        SkillCatalog $skills,
        CampaignResolver $campaigns,
    ): JsonResponse {
        $validated = $request->validate([
            'action_id' => ['required', 'string', 'max:64'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'skill_id' => ['required', 'string', Rule::in($skills->ids())],
            'target' => ['nullable', 'string', Rule::in(Element::values())],
        ], [
            'action_id.required' => '缺少 action_id',
            'action_id.string' => 'action_id 格式錯誤',
            'action_id.max' => 'action_id 長度不可超過 64 字',
            'expected_version.required' => '缺少 expected_version',
            'expected_version.integer' => 'expected_version 必須是整數',
            'expected_version.min' => 'expected_version 必須大於等於 1',
            'skill_id.required' => '缺少 skill_id',
            'skill_id.string' => 'skill_id 格式錯誤',
            'skill_id.in' => '這個技能不存在',
            'target.string' => 'target 格式錯誤',
            'target.in' => '這個目標系別不存在',
        ]);

        $model = RunController::ownedRun($campaigns->existing($request), $run);

        $action = new ActionRequest(
            actionId: $validated['action_id'],
            expectedVersion: $validated['expected_version'],
            skillId: $validated['skill_id'],
            target: isset($validated['target']) ? Element::from($validated['target']) : null,
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
            ], 409);
        } catch (InvalidActionException $exception) {
            // 422：規則不合法，交易沒有寫入，回合與惡意都沒有被消耗。
            return response()->json([
                'reason_code' => $exception->reasonCode,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
                'current_version' => $model->fresh()->version,
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
