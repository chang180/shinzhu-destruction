<?php

namespace App\Services\OpenData;

use App\Services\OpenData\Snapshot\NormalizedSnapshot;

/**
 * 單一來源的更新結果。失敗時 kept_snapshot_id 說明目前仍在使用哪一份快照。
 */
final readonly class RefreshResult
{
    private function __construct(
        public string $sourceId,
        public bool $published,
        public ?NormalizedSnapshot $snapshot,
        public ?string $reasonCode,
        public ?string $message,
        public ?string $keptSnapshotId,
    ) {}

    public static function published(NormalizedSnapshot $snapshot): self
    {
        return new self($snapshot->sourceId, true, $snapshot, null, null, null);
    }

    public static function failed(string $sourceId, string $reasonCode, string $message, ?string $keptSnapshotId): self
    {
        return new self($sourceId, false, null, $reasonCode, $message, $keptSnapshotId);
    }
}
