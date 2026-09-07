<?php

namespace App\Services\OpenData\Snapshot;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * 正規化快照。這是遊戲端唯一可以讀的資料形狀；欄位定義見 docs/DATA-CONTRACT.md。
 *
 * 一場對局開始後綁定一組 snapshot_id 並凍結，不在回合中重新抓取上游。
 */
final readonly class NormalizedSnapshot
{
    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $metrics
     * @param  array<string, string>  $units
     * @param  list<array{code: string, message: string, context?: array<string, mixed>}>  $warnings
     */
    public function __construct(
        public string $snapshotId,
        public string $sourceId,
        public string $schemaVersion,
        public string $resourceUrl,
        public string $contentHash,
        public CarbonImmutable $fetchedAt,
        public ?CarbonImmutable $observedAt,
        public ?SnapshotPeriod $period,
        public SnapshotQuality $quality,
        public string $qualityReason,
        public array $scope,
        public array $metrics,
        public array $units,
        public array $warnings = [],
        public ?int $byteSize = null,
    ) {}

    public static function newId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * 只換品質標示，不動任何量測值。用於 stale 判定與備援標記。
     */
    public function withQuality(SnapshotQuality $quality, string $reason): self
    {
        return new self(
            $this->snapshotId,
            $this->sourceId,
            $this->schemaVersion,
            $this->resourceUrl,
            $this->contentHash,
            $this->fetchedAt,
            $this->observedAt,
            $this->period,
            $quality,
            $reason,
            $this->scope,
            $this->metrics,
            $this->units,
            $this->warnings,
            $this->byteSize,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'source_id' => $this->sourceId,
            'schema_version' => $this->schemaVersion,
            'resource_url' => $this->resourceUrl,
            'content_hash' => $this->contentHash,
            'fetched_at' => $this->fetchedAt->utc()->toIso8601String(),
            'observed_at' => $this->observedAt?->utc()->toIso8601String(),
            'period' => $this->period?->toArray(),
            'quality' => $this->quality->value,
            'quality_reason' => $this->qualityReason,
            'scope' => $this->scope,
            'metrics' => $this->metrics,
            'units' => $this->units,
            'warnings' => $this->warnings,
            'byte_size' => $this->byteSize,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromArray(array $snapshot): self
    {
        return new self(
            $snapshot['snapshot_id'],
            $snapshot['source_id'],
            $snapshot['schema_version'],
            $snapshot['resource_url'],
            $snapshot['content_hash'],
            CarbonImmutable::parse($snapshot['fetched_at'])->utc(),
            isset($snapshot['observed_at']) ? CarbonImmutable::parse($snapshot['observed_at'])->utc() : null,
            isset($snapshot['period']) ? SnapshotPeriod::fromArray($snapshot['period']) : null,
            SnapshotQuality::from($snapshot['quality']),
            $snapshot['quality_reason'],
            $snapshot['scope'],
            $snapshot['metrics'],
            $snapshot['units'],
            $snapshot['warnings'] ?? [],
            $snapshot['byte_size'] ?? null,
        );
    }
}
