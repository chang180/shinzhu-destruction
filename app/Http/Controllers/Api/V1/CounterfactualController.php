<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Game\Exceptions\InvalidActionException;
use App\Http\Controllers\Controller;
use App\Services\Game\CampaignResolver;
use App\Services\Game\CounterfactualComparator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CounterfactualController extends Controller
{
    /**
     * P07 反事實比較：不改動任何一局，只回答「如果第 N 筆行動換成別的，
     * 剩下的回合交給模擬策略接手打，結果會不會不一樣」。
     */
    public function store(
        Request $request,
        string $run,
        CampaignResolver $campaigns,
        CounterfactualComparator $comparator,
    ): JsonResponse {
        $validated = $request->validate([
            'sequence' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in(['play', 'swap', 'timeout'])],
            'card_id' => ['nullable', 'string', 'max:64'],
            'fixed' => ['nullable', 'string', Rule::in(config('game.fixed_actions'))],
            'keep' => ['array', 'max:5'],
            'keep.*' => ['string', 'max:64'],
            'strategy' => ['nullable', 'string', Rule::in(['planner', 'greedy'])],
        ], [
            'sequence.required' => '缺少要比較的行動序號',
            'sequence.integer' => 'sequence 必須是整數',
            'type.required' => '缺少行動類型',
            'type.in' => '這個行動類型不能拿來比較（揭牌不是決策）',
            'fixed.in' => '這不是手牌旁的固定行動',
        ]);

        $model = RunController::ownedRun($campaigns->existing($request), $run);

        if (! $model->outcome->isFinished()) {
            return response()->json([
                'reason_code' => 'run_in_progress',
                'message' => '這一局還沒結束，反事實比較要等結算之後',
            ], 409);
        }

        try {
            $comparison = $comparator->compare(
                $model,
                $validated['sequence'],
                [
                    'type' => $validated['type'],
                    'card_id' => $validated['card_id'] ?? null,
                    'fixed' => $validated['fixed'] ?? null,
                    'keep' => $validated['keep'] ?? [],
                ],
                $validated['strategy'] ?? 'planner',
            );
        } catch (InvalidActionException $exception) {
            // 422：換上去的行動本身不合法，或指定的回合不是可比較的決策。
            // 這一局完全沒有被動到，不需要 current_version 之類的重新同步資訊。
            return response()->json([
                'reason_code' => $exception->reasonCode,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
            ], 422);
        }

        return response()->json($comparison);
    }
}
