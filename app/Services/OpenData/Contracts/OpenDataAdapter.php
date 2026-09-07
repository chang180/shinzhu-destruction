<?php

namespace App\Services\OpenData\Contracts;

use App\Services\OpenData\Exceptions\DataSourceException;
use App\Services\OpenData\Snapshot\NormalizedSnapshot;
use App\Services\OpenData\Support\RawPayload;

/**
 * 每個中央部會資料來源實作一次。抓取與正規化分開，讓測試可以用固定 fixture
 * 直接驗證正規化，不必依賴上游。
 */
interface OpenDataAdapter
{
    public function sourceId(): string;

    public function resourceUrl(): string;

    /**
     * @throws DataSourceException
     */
    public function fetch(): RawPayload;

    /**
     * @throws DataSourceException
     */
    public function normalize(RawPayload $payload): NormalizedSnapshot;
}
