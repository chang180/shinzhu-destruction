<?php

namespace App\Services\OpenData\Support;

use Carbon\CarbonImmutable;

/**
 * 已通過傳輸層防護的原始回應內容。adapter 只從這裡拿位元組，不自己發請求。
 */
final readonly class RawPayload
{
    public function __construct(
        public string $sourceId,
        public string $resourceUrl,
        public string $body,
        public string $contentType,
        public int $byteSize,
        public string $contentHash,
        public CarbonImmutable $fetchedAt,
        public int $attempts,
    ) {}

    public static function make(
        string $sourceId,
        string $resourceUrl,
        string $body,
        string $contentType,
        CarbonImmutable $fetchedAt,
        int $attempts,
    ): self {
        return new self(
            $sourceId,
            $resourceUrl,
            $body,
            $contentType,
            strlen($body),
            hash('sha256', $body),
            $fetchedAt,
            $attempts,
        );
    }

    /**
     * 去除 UTF-8 BOM 後的內容。三個來源的 CSV 都帶 BOM。
     */
    public function bodyWithoutBom(): string
    {
        return str_starts_with($this->body, "\u{FEFF}")
            ? substr($this->body, 3)
            : $this->body;
    }
}
