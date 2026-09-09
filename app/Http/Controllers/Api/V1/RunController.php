<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Game\LevelRepository;
use App\Domain\Game\RunMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RunResource;
use App\Models\Campaign;
use App\Models\Run;
use App\Services\Game\CampaignResolver;
use App\Services\Game\Exceptions\RunConflictException;
use App\Services\Game\RunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RunController extends Controller
{
    public function index(Request $request, CampaignResolver $campaigns): JsonResponse
    {
        $campaign = $campaigns->existing($request);
        $runs = $campaign === null ? collect() : Run::query()
            ->where('campaign_id', $campaign->id)->latest('id')->limit(20)->get();

        return response()->json(['data' => $runs->map(static fn (Run $run): array => [
            'run_id' => $run->public_id,
            'level_id' => $run->level_id,
            'mode' => $run->mode,
            'outcome' => $run->outcome->value,
            'turn' => $run->state['turn'],
            'updated_at' => $run->updated_at->toIso8601String(),
        ])->all()]);
    }

    public function retry(Request $request, string $run, CampaignResolver $campaigns, RunService $runs): JsonResponse
    {
        $original = self::ownedRun($campaigns->existing($request), $run);
        try {
            $retry = $runs->retry($original);
        } catch (RunConflictException $exception) {
            return response()->json(['reason_code' => $exception->reasonCode, 'message' => $exception->getMessage()], 409);
        }

        return (new RunResource($retry))->response()->setStatusCode(201);
    }

    public function store(
        Request $request,
        RunService $runs,
        LevelRepository $levels,
        CampaignResolver $campaigns,
    ): JsonResponse {
        $validated = $request->validate([
            'level_id' => ['required', 'string', Rule::in($levels->ids())],
            'mode' => ['nullable', 'string', Rule::in(array_column(RunMode::cases(), 'value'))],
        ], [
            'level_id.required' => '缺少 level_id',
            'level_id.string' => 'level_id 格式錯誤',
            'level_id.in' => '這一關不存在',
            'mode.in' => '模式只能是限時挑戰或不限時練習',
        ]);

        $campaign = $campaigns->resolve($request);
        $level = $levels->get($validated['level_id']);
        $mode = RunMode::from($validated['mode'] ?? RunMode::Challenge->value);

        /*
         * 內容未交付的關卡不能開局。這和解鎖是兩回事：解鎖是玩家的進度，
         * `available` 是「這一關的規則與數值做完了沒有」，硬開只會讓玩家玩到
         * 一組還沒驗證的數值，然後把它當成正式難度。
         */
        if (! $level->available) {
            return response()->json([
                'reason_code' => 'level_unavailable',
                'message' => '這一關的內容尚未交付，目前只能挑戰已完成的關卡',
            ], 409);
        }

        $unlocked = $mode === RunMode::Practice
            ? array_values(array_unique(array_merge($campaigns->initiallyUnlocked(), $campaign->practiceUnlocked())))
            : $campaign->unlocked;

        if ($level->requires !== null && ! in_array($level->id, $unlocked, true)) {
            return response()->json([
                'reason_code' => 'level_locked',
                'message' => '這一關尚未解鎖',
            ], 403);
        }

        $run = $runs->create($campaign, $level->id, $mode);

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
