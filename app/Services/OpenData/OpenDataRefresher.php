<?php

namespace App\Services\OpenData;

use App\Services\OpenData\Exceptions\DataSourceException;
use Illuminate\Support\Facades\Log;

/**
 * 抓取 → 驗證 → 清洗 → 原子發布。任何一步失敗都直接回報失敗，
 * 不會刪除或覆寫既有快照。
 */
class OpenDataRefresher
{
    public function __construct(
        private readonly OpenDataRegistry $registry,
        private readonly SnapshotRepository $snapshots,
    ) {}

    public function refresh(string $sourceId): RefreshResult
    {
        $adapter = $this->registry->adapter($sourceId);

        try {
            $payload = $adapter->fetch();
            $snapshot = $adapter->normalize($payload);
        } catch (DataSourceException $exception) {
            $kept = $this->snapshots->current($sourceId);

            Log::warning('資料來源更新失敗，保留既有快照', [
                'source_id' => $sourceId,
                'reason_code' => $exception->reasonCode,
                'kept_snapshot_id' => $kept?->snapshot_id,
            ]);

            return RefreshResult::failed(
                $sourceId,
                $exception->reasonCode,
                $exception->getMessage(),
                $kept?->snapshot_id,
            );
        }

        $this->snapshots->publish($snapshot);

        return RefreshResult::published($snapshot);
    }

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, RefreshResult>
     */
    public function refreshMany(array $sourceIds): array
    {
        $results = [];

        foreach ($sourceIds as $sourceId) {
            $results[$sourceId] = $this->refresh($sourceId);
        }

        return $results;
    }
}
