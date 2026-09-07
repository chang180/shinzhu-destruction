<?php

namespace App\Services\OpenData\Support;

use App\Services\OpenData\Exceptions\DataSourceException;

/**
 * 兩個 CSV 來源共用的解析。空字串、`--`、`N/A` 一律轉成缺值並保留原因；
 * `0` 是有效值，不能當缺值。
 */
final readonly class CsvTable
{
    private const MISSING_TOKENS = ['', '-', '--', 'N/A', 'n/a', 'NA', '_'];

    /**
     * @param  list<string>  $header
     * @param  list<array<string, string>>  $rows
     */
    private function __construct(
        public array $header,
        public array $rows,
    ) {}

    /**
     * @throws DataSourceException
     */
    public static function parse(string $sourceId, string $csv): self
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, escape: '');

        if ($header === false || $header === null || $header === [null]) {
            fclose($handle);

            throw DataSourceException::payloadUnparsable($sourceId, 'CSV 沒有標題列');
        }

        $header = array_map(static fn (?string $column): string => trim((string) $column), $header);
        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            if (count($row) !== count($header)) {
                continue;
            }

            $rows[] = array_combine($header, array_map(static fn (?string $cell): string => trim((string) $cell), $row));
        }

        fclose($handle);

        if ($rows === []) {
            throw DataSourceException::payloadUnparsable($sourceId, 'CSV 沒有任何資料列');
        }

        return new self($header, $rows);
    }

    /**
     * @param  list<string>  $columns
     *
     * @throws DataSourceException
     */
    public function requireColumns(string $sourceId, array $columns): void
    {
        $missing = array_values(array_diff($columns, $this->header));

        if ($missing !== []) {
            throw DataSourceException::missingRequiredFields(
                $sourceId,
                '缺少必要欄位：'.implode('、', $missing),
                ['missing_columns' => $missing],
            );
        }
    }

    /**
     * 缺值回傳 null；解析不出數字也回傳 null，由呼叫端記錄警告。
     */
    public static function toFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([',', ' '], '', trim($value));

        if (in_array($value, self::MISSING_TOKENS, true)) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
