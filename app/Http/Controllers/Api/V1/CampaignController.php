<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Game\LevelRepository;
use App\Http\Controllers\Controller;
use App\Services\Game\CampaignProgress;
use App\Services\Game\CampaignResolver;
use App\Services\Game\DeckComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 戰役層級的進度：牌組獎勵與主線收手。
 *
 * 兩個寫入端點都只動戰役，不動任何對局——已經開始的那一局在開局時就凍結了牌組，
 * 之後挑的牌只會影響下一局。
 */
class CampaignController extends Controller
{
    public function show(Request $request, CampaignResolver $campaigns, CampaignProgress $progress): JsonResponse
    {
        return response()->json(['data' => $progress->payload($campaigns->existing($request))]);
    }

    public function reward(
        Request $request,
        CampaignResolver $campaigns,
        CampaignProgress $progress,
        DeckComposer $decks,
        LevelRepository $levels,
    ): JsonResponse {
        $validated = $request->validate([
            'level_id' => ['required', 'string', Rule::in($levels->ids())],
            'option' => ['required', 'string'],
        ], [
            'level_id.in' => '這一關不存在',
            'option.required' => '缺少選項',
        ]);

        $campaign = $campaigns->existing($request);

        if ($campaign === null || ! $campaign->hasCleared($validated['level_id'])) {
            return response()->json([
                'reason_code' => 'reward_not_earned',
                'message' => '這一關還沒有限時挑戰通關紀錄，沒有可挑的牌',
            ], 403);
        }

        $level = $levels->get($validated['level_id']);

        if ($level->reward === null) {
            return response()->json(['reason_code' => 'no_reward', 'message' => '這一關沒有牌組獎勵'], 409);
        }

        if (array_key_exists($level->id, $campaign->deckChoices())) {
            return response()->json([
                'reason_code' => 'reward_already_taken',
                'message' => '這一關的牌已經挑過了，不能重挑',
            ], 409);
        }

        if (! $decks->canApply($level, $campaign, $validated['option'])) {
            return response()->json([
                'reason_code' => 'reward_option_invalid',
                'message' => '這個選項無法套用到目前的牌組',
            ], 422);
        }

        $progress->chooseReward($campaign, $level->id, $validated['option']);

        return response()->json(['data' => $progress->payload($campaign->fresh())]);
    }

    public function standDown(Request $request, CampaignResolver $campaigns, CampaignProgress $progress): JsonResponse
    {
        $campaign = $campaigns->existing($request);

        if ($campaign === null || ! $campaign->hasMilestone(CampaignProgress::MAIN_CLEARED)) {
            return response()->json([
                'reason_code' => 'main_not_cleared',
                'message' => '主線目標尚未完成，還沒有可以收下的戰果',
            ], 403);
        }

        $progress->standDown($campaign);

        return response()->json(['data' => $progress->payload($campaign->fresh())]);
    }
}
