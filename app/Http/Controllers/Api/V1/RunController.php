<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Game\LevelRepository;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RunResource;
use App\Models\Campaign;
use App\Models\Run;
use App\Services\Game\CampaignResolver;
use App\Services\Game\RunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RunController extends Controller
{
    public function store(
        Request $request,
        RunService $runs,
        LevelRepository $levels,
        CampaignResolver $campaigns,
    ): JsonResponse {
        $validated = $request->validate([
            'level_id' => ['required', 'string', Rule::in($levels->ids())],
        ]);

        $campaign = $campaigns->resolve($request);
        $level = $levels->get($validated['level_id']);

        if ($level->requires !== null && ! in_array($level->id, $campaign->unlocked, true)) {
            return response()->json([
                'reason_code' => 'level_locked',
                'message' => '這一關尚未解鎖',
            ], 403);
        }

        $run = $runs->create($campaign, $level->id);

        return (new RunResource($run))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $run, CampaignResolver $campaigns): JsonResponse
    {
        return (new RunResource(self::ownedRun($campaigns->existing($request), $run)))->response();
    }

    /**
     * 擁有者隔離：不是自己的局一律 404，不用 403 洩漏「這個 run 存在」。
     * 連戰役都還沒有的訪客同樣是 404，不必為了拒絕他而先建立一個戰役。
     */
    public static function ownedRun(?Campaign $campaign, string $publicId): Run
    {
        if ($campaign === null) {
            throw new NotFoundHttpException('找不到這一局');
        }

        $run = Run::query()
            ->where('campaign_id', $campaign->id)
            ->where('public_id', $publicId)
            ->first();

        if ($run === null) {
            throw new NotFoundHttpException('找不到這一局');
        }

        return $run;
    }
}
