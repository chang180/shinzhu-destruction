<?php

namespace App\Services\OpenData\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 受控的資料來源失敗。reason_code 是穩定字串，會寫進日誌與來源狀態，
 * 但不把上游完整回應或內部路徑往前端送。
 */
class DataSourceException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $reasonCode,
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transportTimeout(string $sourceId, string $message, ?Throwable $previous = null): self
    {
        return new self($sourceId, 'transport_timeout', $message, [], $previous);
    }

    public static function budgetExhausted(string $sourceId, int $budgetSeconds): self
    {
        return new self($sourceId, 'budget_exhausted', "來源工作預算 {$budgetSeconds} 秒已用盡", [
            'budget_seconds' => $budgetSeconds,
        ]);
    }

    public static function httpStatus(string $sourceId, int $status): self
    {
        return new self($sourceId, 'http_status_'.$status, "上游回應 HTTP {$status}", [
            'status' => $status,
        ]);
    }

    public static function payloadTooLarge(string $sourceId, int $maxBytes): self
    {
        return new self($sourceId, 'payload_too_large', "回應超過 {$maxBytes} bytes 上限", [
            'max_bytes' => $maxBytes,
        ]);
    }

    public static function unexpectedContentType(string $sourceId, string $contentType): self
    {
        return new self($sourceId, 'unexpected_content_type', "非預期的 Content-Type：{$contentType}", [
            'content_type' => $contentType,
        ]);
    }

    public static function htmlMasquerade(string $sourceId): self
    {
        return new self($sourceId, 'html_masquerade', '上游回應 2xx 但內容是 HTML／腳本，判定為假成功');
    }

    public static function redirectNotAllowed(string $sourceId, string $host): self
    {
        return new self($sourceId, 'redirect_not_allowed', "重新導向到未核實主機：{$host}", [
            'host' => $host,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function payloadUnparsable(string $sourceId, string $message, array $context = []): self
    {
        return new self($sourceId, 'payload_unparsable', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function missingRequiredFields(string $sourceId, string $message, array $context = []): self
    {
        return new self($sourceId, 'missing_required_fields', $message, $context);
    }
}
