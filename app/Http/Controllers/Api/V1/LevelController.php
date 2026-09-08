<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Game\LevelRepository;
use App\Domain\Game\SkillCatalog;
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
        SkillCatalog $skills,
        LevelRepository $levels,
        CampaignResolver $campaigns,
        SnapshotRepository $snapshots,
        OpenDataRegistry $sources,
    ): JsonResponse {
        // 唯讀端點：還沒開過局的訪客看預設解鎖狀態，不為了讀一份清單就建立戰役。
        $campaign = $campaigns->existing($request);
        $unlocked = $campaign?->unlocked ?? $campaigns->initiallyUnlocked();
        $best = $campaign?->best_results ?? [];

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
            'skills' => collect($skills->all())->map(static fn ($skill): array => [
                'id' => $skill->id, 'kind' => $skill->kind->value, 'element' => $skill->element?->value,
                'malice_cost' => $skill->maliceCost, 'cooldown' => $skill->cooldown,
                'base_impact' => $skill->baseImpact, 'defense_delta' => $skill->defenseDelta,
                'required_sigils' => $skill->requiredSigilsPerElement,
            ])->all(),
            'data_status' => $snapshots->status($sources->sourceIds()),
        ]);
    }
}
