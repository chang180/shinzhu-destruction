<?php

namespace App\Services\OpenData\Support;

use App\Services\OpenData\Exceptions\DataSourceException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * 三個來源共用的下載防護：固定 UA、TLS 驗證、逾時、位元組上限、
 * 重新導向主機白名單、總工作預算與有限重試。
 *
 * 429／4xx 一律視為受控失敗，不做無限重試。
 */
class GuardedDownloader
{
    private const CHUNK_BYTES = 65536;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public function __construct(private readonly string $userAgent) {}

    /**
     * @param  array{
     *     resource_url: string,
     *     accept?: string,
     *     allowed_hosts: list<string>,
     *     expected_content_types: list<string>,
     *     max_bytes: int,
     *     connect_timeout: int,
     *     request_timeout: int,
     *     budget_seconds: int,
     *     max_attempts: int,
     *     retry_delay_ms: int,
     * }  $source
     *
     * @throws DataSourceException
     */
    public function download(string $sourceId, array $source): RawPayload
    {
        $deadline = microtime(true) + $source['budget_seconds'];
        $attempts = 0;
        $lastFailure = null;

        while ($attempts < $source['max_attempts']) {
            if (microtime(true) >= $deadline) {
                throw $lastFailure ?? DataSourceException::budgetExhausted($sourceId, $source['budget_seconds']);
            }

            $attempts++;

            try {
                $response = $this->request($sourceId, $source);
            } catch (ConnectionException $exception) {
                $lastFailure = DataSourceException::transportTimeout(
                    $sourceId,
                    '連線失敗或逾時',
                    $exception,
                );
                $this->pause($source['retry_delay_ms'], $deadline);

                continue;
            }

            if ($response->failed()) {
                $failure = DataSourceException::httpStatus($sourceId, $response->status());

                if (! in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
                    throw $failure;
                }

                $lastFailure = $failure;
                $this->pause($source['retry_delay_ms'], $deadline);

                continue;
            }

            return $this->readBody($sourceId, $source, $response, $attempts);
        }

        throw $lastFailure ?? DataSourceException::budgetExhausted($sourceId, $source['budget_seconds']);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function request(string $sourceId, array $source): Response
    {
        return Http::withUserAgent($this->userAgent)
            ->withHeaders(array_filter(['Accept' => $source['accept'] ?? null]))
            ->connectTimeout($source['connect_timeout'])
            ->timeout($source['request_timeout'])
            ->withOptions([
                'stream' => true,
                'verify' => true,
                'allow_redirects' => [
                    'max' => 3,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['https'],
                    'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($sourceId, $source): void {
                        if (! in_array($uri->getHost(), $source['allowed_hosts'], true)) {
                            throw DataSourceException::redirectNotAllowed($sourceId, $uri->getHost());
                        }
                    },
                ],
            ])
            ->get($source['resource_url']);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function readBody(string $sourceId, array $source, Response $response, int $attempts): RawPayload
    {
        $contentType = $this->normalizeContentType($response->header('Content-Type'));

        if ($contentType !== '' && ! in_array($contentType, $source['expected_content_types'], true)) {
            throw DataSourceException::unexpectedContentType($sourceId, $contentType);
        }

        $stream = $response->toPsrResponse()->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(self::CHUNK_BYTES);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;

            if (strlen($body) > $source['max_bytes']) {
                throw DataSourceException::payloadTooLarge($sourceId, $source['max_bytes']);
            }
        }

        if ($this->looksLikeMarkup($body)) {
            throw DataSourceException::htmlMasquerade($sourceId);
        }

        return RawPayload::make(
            $sourceId,
            $source['resource_url'],
            $body,
            $contentType,
            CarbonImmutable::now('UTC'),
            $attempts,
        );
    }

    private function normalizeContentType(?string $header): string
    {
        if ($header === null || $header === '') {
            return '';
        }

        return strtolower(trim(explode(';', $header, 2)[0]));
    }

    /**
     * 部分來源在找不到檔案時仍回 2xx，內容卻是 HTML 或 JavaScript 提示視窗。
     * 只看開頭字元，避免誤判以 `<` 開頭以外的合法 CSV／JSON。
     */
    private function looksLikeMarkup(string $body): bool
    {
        $head = ltrim(substr($body, 0, 512), "\u{FEFF} \t\r\n");

        return str_starts_with($head, '<');
    }

    private function pause(int $delayMs, float $deadline): void
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            return;
        }

        usleep((int) min($delayMs * 1000, $remaining * 1_000_000));
    }
}
