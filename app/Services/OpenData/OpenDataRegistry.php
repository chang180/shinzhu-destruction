<?php

namespace App\Services\OpenData;

use App\Services\OpenData\Adapters\AbstractAdapter;
use App\Services\OpenData\Contracts\OpenDataAdapter;
use App\Services\OpenData\Support\GuardedDownloader;
use InvalidArgumentException;

/**
 * config/opendata.php 是唯一的來源清單。新增來源不需要改命令或排程程式碼。
 */
class OpenDataRegistry
{
    /** @var array<string, OpenDataAdapter> */
    private array $adapters = [];

    /**
     * @param  array<string, array<string, mixed>>  $sources
     */
    public function __construct(
        private readonly array $sources,
        private readonly string $schemaVersion,
        private readonly GuardedDownloader $downloader,
    ) {}

    /**
     * @return list<string>
     */
    public function sourceIds(): array
    {
        return array_keys($this->sources);
    }

    public function has(string $sourceId): bool
    {
        return array_key_exists($sourceId, $this->sources);
    }

    /**
     * @return array<string, mixed>
     */
    public function source(string $sourceId): array
    {
        if (! $this->has($sourceId)) {
            throw new InvalidArgumentException("未知的資料來源：{$sourceId}");
        }

        return $this->sources[$sourceId];
    }

    public function adapter(string $sourceId): OpenDataAdapter
    {
        return $this->adapters[$sourceId] ??= $this->makeAdapter($sourceId);
    }

    /**
     * @return list<string>
     */
    public function sourceIdsForSchedule(string $schedule): array
    {
        return array_values(array_keys(array_filter(
            $this->sources,
            static fn (array $source): bool => ($source['schedule'] ?? null) === $schedule,
        )));
    }

    private function makeAdapter(string $sourceId): OpenDataAdapter
    {
        $source = $this->source($sourceId);
        /** @var class-string<AbstractAdapter> $class */
        $class = $source['adapter'];

        return new $class($sourceId, $source, $this->schemaVersion, $this->downloader);
    }
}
