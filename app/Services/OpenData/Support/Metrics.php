<?php

namespace App\Services\OpenData\Support;

/**
 * 情境指標的共用計算。所有比值都是「同一標的、同一欄位、同一單位」的自我比較，
 * 不跨來源相加，也不推算上游沒有提供的百分比。
 */
final class Metrics
{
    /**
     * @param  list<float>  $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        $median = $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;

        return self::round($median);
    }

    /**
     * 相對指標＝最新值 ÷ 同標的中位數。樣本不足或分母為 0 時回傳 null，
     * 由呼叫端改用中性值，不得填 1.0 假裝有資料。
     *
     * @param  list<float>  $samples
     */
    public static function relativeIndex(?float $latest, array $samples, int $minimumSamples): ?float
    {
        if ($latest === null || count($samples) < $minimumSamples) {
            return null;
        }

        $median = self::median($samples);

        if ($median === null || $median <= 0.0) {
            return null;
        }

        return self::round($latest / $median, 4);
    }

    public static function ratio(?float $part, ?float $total): ?float
    {
        if ($part === null || $total === null || $total <= 0.0) {
            return null;
        }

        return self::round($part / $total, 6);
    }

    public static function round(?float $value, int $precision = 3): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
