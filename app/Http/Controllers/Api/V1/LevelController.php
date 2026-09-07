<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Game\LevelRepository;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LevelResource;
use App\Services\Game\CampaignResolver;
use App\Services\OpenData\OpenDataRegistry;
use App\Services\OpenData\SnapshotRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LevelController extends Controller
{
    public function index(
        Request $request,
        LevelRepository $levels,
        CampaignResolver $campaigns,
        SnapshotRepository $snapshots,
        OpenDataRegistry $sources,
    ): JsonResponse {
        $campaign = $campaigns->resolve($request);
        $unlocked = $campaign->unlocked;
        $best = $campaign->best_results;

        $payload = [];

        foreach ($levels->all() as $levelId => $level) {
            $payload[] = new LevelResource(
                $level,
                in_array($levelId, $unlocked, true),
                $best[$levelId] ?? null,
            );
        }

        return response()->json([
            'rules_version' => config('game.rules_version'),
            'levels' => $payload,
            'data_status' => $snapshots->status($sources->sourceIds()),
        ]);
    }
}
