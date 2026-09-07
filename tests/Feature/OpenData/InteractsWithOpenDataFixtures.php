<?php

namespace Tests\Feature\OpenData;

use App\Services\OpenData\Adapters\AbstractAdapter;
use App\Services\OpenData\Support\GuardedDownloader;
use App\Services\OpenData\Support\RawPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

trait InteractsWithOpenDataFixtures
{
    protected function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/OpenData/'.$name);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function adapterFor(string $sourceId, array $overrides = []): AbstractAdapter
    {
        $source = array_replace_recursive($this->sourceConfig($sourceId), $overrides);
        /** @var class-string<AbstractAdapter> $class */
        $class = $source['adapter'];

        return new $class(
            $sourceId,
            $source,
            Config::get('opendata.schema_version'),
            new GuardedDownloader('phpunit'),
        );
    }

    /**
     * source_id 含有 `.`，不能用 Config 的點記法取值。
     *
     * @return array<string, mixed>
     */
    protected function sourceConfig(string $sourceId): array
    {
        return Config::get('opendata.sources')[$sourceId];
    }

    protected function payload(string $sourceId, string $body, string $fetchedAt = '2026-09-08T00:00:00Z'): RawPayload
    {
        return RawPayload::make(
            $sourceId,
            $this->sourceConfig($sourceId)['resource_url'],
            $body,
            'application/json',
            CarbonImmutable::parse($fetchedAt)->utc(),
            1,
        );
    }
}
