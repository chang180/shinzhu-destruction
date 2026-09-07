<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OpenData\OpenDataRegistry;
use App\Services\OpenData\SnapshotRepository;
use Illuminate\Http\JsonResponse;

class DataStatusController extends Controller
{
    /**
     * 去秘密化的來源品質與觀測時間。不顯示密鑰或完整內部錯誤。
     */
    public function show(SnapshotRepository $snapshots, OpenDataRegistry $sources): JsonResponse
    {
        return response()->json([
            'sources' => $snapshots->status($sources->sourceIds()),
        ]);
    }
}
