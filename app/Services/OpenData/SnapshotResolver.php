<?php

namespace App\Services\OpenData;

use App\Services\OpenData\Snapshot\NormalizedSnapshot;

/**
 * 開局時解析一組要凍結的快照。
 *
 * 順序：目前有效快照（fresh 或 stale 都算有效） → 版本化示範情境 fixture。
 * 解析後回傳的 snapshot_id 由對局保存，之後不再重新解析，
 * 讓同一局中途不會換資料。
 */
class SnapshotResolver
{
    public function __construct(
        private readonly OpenDataRegistry $registry,
        private readonly SnapshotRepository $snapshots,
        private readonly FixtureRepository $fixtures,
    ) {}

    /**
     * @return array<string, NormalizedSnapshot>
     */
    public function resolveSet(): array
    {
        $resolved = [];

        foreach ($this->registry->sourceIds() as $sourceId) {
            $resolved[$sourceId] = $this->resolve($sourceId);
        }

        return $resolved;
    }

    public function resolve(string $sourceId): NormalizedSnapshot
    {
        $current = $this->snapshots->current($sourceId);

        if ($current !== null) {
            return $current->toNormalizedSnapshot();
        }

        return $this->fixtures->load($sourceId);
    }

    /**
     * 整組是否含有非真實資料的來源。UI 用它決定要不要顯示「示範情境」提示。
     *
     * @param  array<string, NormalizedSnapshot>  $set
     */
    public function containsDemoData(array $set): bool
    {
        foreach ($set as $snapshot) {
            if (! $snapshot->quality->isRealData()) {
                return true;
            }
        }

        return false;
    }
}
