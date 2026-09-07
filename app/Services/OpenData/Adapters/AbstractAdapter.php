<?php

namespace App\Services\OpenData\Adapters;

use App\Services\OpenData\Contracts\OpenDataAdapter;
use App\Services\OpenData\Support\GuardedDownloader;
use App\Services\OpenData\Support\RawPayload;

abstract class AbstractAdapter implements OpenDataAdapter
{
    /**
     * @param  array<string, mixed>  $source  config/opendata.php 的單一來源設定
     */
    public function __construct(
        protected readonly string $sourceId,
        protected readonly array $source,
        protected readonly string $schemaVersion,
        protected readonly GuardedDownloader $downloader,
    ) {}

    public function sourceId(): string
    {
        return $this->sourceId;
    }

    public function resourceUrl(): string
    {
        return $this->source['resource_url'];
    }

    public function fetch(): RawPayload
    {
        return $this->downloader->download($this->sourceId, $this->source);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string, message: string, context: array<string, mixed>}
     */
    protected function warning(string $code, string $message, array $context = []): array
    {
        return ['code' => $code, 'message' => $message, 'context' => $context];
    }
}
