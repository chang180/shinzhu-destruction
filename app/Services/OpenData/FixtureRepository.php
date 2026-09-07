<?php

namespace App\Services\OpenData;

use App\Services\OpenData\Snapshot\NormalizedSnapshot;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use RuntimeException;

/**
 * 版本化的示範情境。數值是人工編寫的固定情境，不是任何一次真實觀測，
 * quality 永遠是 demo，UI 必須顯示「示範情境」。
 */
class FixtureRepository
{
    public function __construct(private readonly string $path) {}

    public function has(string $sourceId): bool
    {
        return is_file($this->fixturePath($sourceId));
    }

    public function load(string $sourceId): NormalizedSnapshot
    {
        $file = $this->fixturePath($sourceId);

        if (! is_file($file)) {
            throw new RuntimeException("找不到 {$sourceId} 的示範情境 fixture：{$file}");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        $snapshot = NormalizedSnapshot::fromArray($data);

        if ($snapshot->quality !== SnapshotQuality::Demo) {
            throw new RuntimeException("示範情境 fixture 的 quality 必須是 demo：{$file}");
        }

        return $snapshot;
    }

    private function fixturePath(string $sourceId): string
    {
        return $this->path.DIRECTORY_SEPARATOR.str_replace('.', '_', $sourceId).'.demo.json';
    }
}
