<?php

namespace Tests\Feature\OpenData;

use App\Services\OpenData\Exceptions\DataSourceException;
use App\Services\OpenData\Support\GuardedDownloader;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuardedDownloaderTest extends TestCase
{
    private const SOURCE_ID = 'test.source';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_returns_the_body_with_a_content_hash_and_the_configured_user_agent(): void
    {
        Http::fake(['example.test/*' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json'])]);

        $payload = $this->downloader()->download(self::SOURCE_ID, $this->source());

        $this->assertSame('{"ok":true}', $payload->body);
        $this->assertSame(hash('sha256', '{"ok":true}'), $payload->contentHash);
        $this->assertSame(1, $payload->attempts);

        Http::assertSent(fn ($request) => $request->header('User-Agent') === ['shinzhu-test-agent']);
    }

    public function test_it_stops_immediately_on_a_403_without_retrying(): void
    {
        Http::fake(['example.test/*' => Http::response('forbidden', 403)]);

        try {
            $this->downloader()->download(self::SOURCE_ID, $this->source());
            $this->fail('403 應該直接失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('http_status_403', $exception->reasonCode);
        }

        Http::assertSentCount(1);
    }

    public function test_it_retries_a_429_within_the_attempt_limit_and_then_succeeds(): void
    {
        Http::fake(['example.test/*' => Http::sequence()
            ->push('slow down', 429)
            ->push('{"ok":true}', 200, ['Content-Type' => 'application/json']),
        ]);

        $payload = $this->downloader()->download(self::SOURCE_ID, $this->source(['retry_delay_ms' => 0]));

        $this->assertSame(2, $payload->attempts);
        Http::assertSentCount(2);
    }

    public function test_it_gives_up_after_the_attempt_limit(): void
    {
        Http::fake(['example.test/*' => Http::response('slow down', 429)]);

        try {
            $this->downloader()->download(self::SOURCE_ID, $this->source(['retry_delay_ms' => 0]));
            $this->fail('重試用盡應該失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('http_status_429', $exception->reasonCode);
        }

        Http::assertSentCount(3);
    }

    public function test_it_treats_a_connection_timeout_as_a_controlled_failure(): void
    {
        Http::fake(['example.test/*' => Http::failedConnection()]);

        try {
            $this->downloader()->download(self::SOURCE_ID, $this->source(['retry_delay_ms' => 0]));
            $this->fail('逾時應該失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('transport_timeout', $exception->reasonCode);
        }

        Http::assertSentCount(3);
    }

    public function test_it_rejects_a_200_response_whose_body_is_actually_markup(): void
    {
        Http::fake(['example.test/*' => Http::response(
            "<script>alert('找不到檔案，請聯絡系統管理員');window.close();</script>",
            200,
            ['Content-Type' => 'application/csv'],
        )]);

        try {
            $this->downloader()->download(self::SOURCE_ID, $this->source(['expected_content_types' => ['application/csv']]));
            $this->fail('HTML 假成功應該失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('html_masquerade', $exception->reasonCode);
        }
    }

    public function test_it_rejects_an_unexpected_content_type(): void
    {
        Http::fake(['example.test/*' => Http::response('col\n1', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        try {
            $this->downloader()->download(self::SOURCE_ID, $this->source());
            $this->fail('非預期 Content-Type 應該失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('unexpected_content_type', $exception->reasonCode);
        }
    }

    public function test_it_rejects_a_body_larger_than_the_configured_cap(): void
    {
        Http::fake(['example.test/*' => Http::response(str_repeat('a', 4096), 200, ['Content-Type' => 'application/json'])]);

        try {
            $this->downloader()->download(self::SOURCE_ID, $this->source(['max_bytes' => 1024]));
            $this->fail('超過上限應該失敗');
        } catch (DataSourceException $exception) {
            $this->assertSame('payload_too_large', $exception->reasonCode);
        }
    }

    private function downloader(): GuardedDownloader
    {
        return new GuardedDownloader('shinzhu-test-agent');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function source(array $overrides = []): array
    {
        return array_replace([
            'resource_url' => 'https://example.test/resource',
            'accept' => 'application/json',
            'allowed_hosts' => ['example.test'],
            'expected_content_types' => ['application/json'],
            'max_bytes' => 1024 * 1024,
            'connect_timeout' => 1,
            'request_timeout' => 2,
            'budget_seconds' => 10,
            'max_attempts' => 3,
            'retry_delay_ms' => 1,
        ], $overrides);
    }
}
