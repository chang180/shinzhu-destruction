<?php

namespace App\Services\OpenData;

use App\Models\DataSnapshot;
use App\Services\OpenData\Snapshot\NormalizedSnapshot;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use Illuminate\Support\Facades\DB;

/**
 * 快照的原子發布與讀取。
 *
 * 抓取與清洗都在交易外完成；只有通過驗證的正規化結果才進交易，
 * 交易內把同來源舊列的 is_current 關掉再插入新列。抓取失敗時完全不呼叫這裡，
 * 所以失敗永遠不會覆蓋最近一次有效快照。
 */
class SnapshotRepository
{
    public function publish(NormalizedSnapshot $snapshot): DataSnapshot
    {
        return DB::transaction(function () use ($snapshot): DataSnapshot {
            DataSnapshot::query()
                ->where('source_id', $snapshot->sourceId)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            return DataSnapshot::query()->create([
                'snapshot_id' => $snapshot->snapshotId,
                'source_id' => $snapshot->sourceId,
                'schema_version' => $snapshot->schemaVersion,
                'resource_url' => $snapshot->resourceUrl,
                'content_hash' => $snapshot->contentHash,
                'quality' => $snapshot->quality,
                'quality_reason' => $snapshot->qualityReason,
                'fetched_at' => $snapshot->fetchedAt,
                'observed_at' => $snapshot->observedAt,
                'period_start' => $snapshot->period?->start,
                'period_end' => $snapshot->period?->end,
                'byte_size' => $snapshot->byteSize,
                'payload' => $snapshot->toArray(),
                'is_current' => true,
            ]);
        });
    }

    public function current(string $sourceId): ?DataSnapshot
    {
        return DataSnapshot::query()
            ->where('source_id', $sourceId)
            ->current()
            ->first();
    }

    public function find(string $snapshotId): ?DataSnapshot
    {
        return DataSnapshot::query()->where('snapshot_id', $snapshotId)->first();
    }

    /**
     * 目前有效的快照組。缺少的來源不出現在回傳值，由呼叫端決定備援。
     *
     * @param  list<string>  $sourceIds
     * @return array<string, NormalizedSnapshot>
     */
    public function currentSet(array $sourceIds): array
    {
        $snapshots = DataSnapshot::query()
            ->whereIn('source_id', $sourceIds)
            ->current()
            ->get();

        $set = [];

        foreach ($snapshots as $snapshot) {
            $set[$snapshot->source_id] = $snapshot->toNormalizedSnapshot();
        }

        return $set;
    }

    /**
     * 去秘密化的來源狀態，供 UI 顯示資料品質與觀測期間。
     *
     * @param  list<string>  $sourceIds
     * @return array<string, array<string, mixed>>
     */
    public function status(array $sourceIds): array
    {
        $status = [];

        foreach ($sourceIds as $sourceId) {
            $snapshot = $this->current($sourceId);

            if ($snapshot === null) {
                $status[$sourceId] = [
                    'quality' => SnapshotQuality::Unavailable->value,
                    'quality_label' => SnapshotQuality::Unavailable->label(),
                    'snapshot_id' => null,
                    'fetched_at' => null,
                    'observed_at' => null,
                    'period' => null,
                ];

                continue;
            }

            $payload = $snapshot->payload;

            $status[$sourceId] = [
                'quality' => $snapshot->quality->value,
                'quality_label' => $snapshot->quality->label(),
                'quality_reason' => $snapshot->quality_reason,
                'snapshot_id' => $snapshot->snapshot_id,
                'fetched_at' => $payload['fetched_at'] ?? null,
                'observed_at' => $payload['observed_at'] ?? null,
                'period' => $payload['period'] ?? null,
            ];
        }

        return $status;
    }

    /**
     * 每個來源保留最近 $keep 筆。P03 加入 runs 之後，必須先排除仍被對局引用的
     * snapshot_id 才能啟用定期清理，否則會破壞重播。
     */
    public function prune(string $sourceId, int $keep): int
    {
        $keepIds = DataSnapshot::query()
            ->where('source_id', $sourceId)
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->limit(max($keep, 1))
            ->pluck('id');

        if ($keepIds->isEmpty()) {
            return 0;
        }

        return DataSnapshot::query()
            ->where('source_id', $sourceId)
            ->whereNotIn('id', $keepIds)
            // 目前生效的快照永遠不刪，即使排序因時鐘誤差把它排到後面。
            ->where('is_current', false)
            ->delete();
    }
}
