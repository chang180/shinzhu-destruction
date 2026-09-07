<?php

namespace App\Domain\Game\Scenario;

use App\Domain\Game\Element;
use App\Services\OpenData\Snapshot\NormalizedSnapshot;

/**
 * 把 P02 的正規化快照換算成每系 -15%～+15% 的情境修正。
 *
 * 三條規則貫穿全部推導：
 *
 * 1. 只讀 docs/DATA-CONTRACT.md 定義過的欄位；欄位是 null 就回中性 0，
 *    絕不用 1.0 或 0 冒充有資料。
 * 2. 訊號一律先正規化到 [-1, 1]，再乘上 0.15 的上限，所以修正永遠夾在範圍內。
 * 3. 修正是**玩家傷害的乘數**：正值代表城市該系較脆弱、玩家好打；
 *    負值代表城市該系較穩、玩家難打。這是遊戲情境，不是真實風險排序。
 *
 * 土地與熱系使用新竹縣**全縣彙總值**，並以同一份快照內 13 個鄉鎮市的
 * 最小／最大值當作正規化刻度。刻度只是尺，不代表對個別鄉鎮市評分，
 * 也沒有把任何關卡綁到特定行政區。
 */
class ScenarioModifierCalculator
{
    public function __construct(
        private readonly float $ratioSaturation,
        private readonly float $modifierLimit,
    ) {}

    /**
     * @param  array<string, NormalizedSnapshot>  $snapshots  source_id => 快照
     */
    public function calculate(array $snapshots): ScenarioModifiers
    {
        $modifiers = [];
        $reasons = [];

        [$modifiers[Element::Water->value], $reasons[Element::Water->value]] = $this->water($snapshots);
        [$modifiers[Element::Heat->value], $reasons[Element::Heat->value]] = $this->heat($snapshots);
        [$modifiers[Element::Land->value], $reasons[Element::Land->value]] = $this->land($snapshots);

        return new ScenarioModifiers($modifiers, $reasons);
    }

    /**
     * 水系＝園區需求訊號減水庫蓄水訊號的平均。
     *
     * 需求高（demand_index > 1）代表城市補給吃緊，玩家好打；
     * 蓄水高（storage_index > 1）代表補給充裕，玩家難打。
     *
     * @param  array<string, NormalizedSnapshot>  $snapshots
     * @return array{0: float, 1: array{code: string, message: string, inputs: array<string, mixed>}}
     */
    private function water(array $snapshots): array
    {
        $storage = $this->meanRatio($snapshots['wra.reservoir_conditions'] ?? null, 'storage_index');
        $demand = $this->meanHsinchuDemand($snapshots['nstc.science_park_water'] ?? null);

        if ($storage === null && $demand === null) {
            return [0.0, $this->reason('missing_inputs', '水庫與園區指標都沒有可用值，採中性修正', [])];
        }

        $signals = [];

        if ($storage !== null) {
            $signals[] = -$this->ratioSignal($storage);
        }

        if ($demand !== null) {
            $signals[] = $this->ratioSignal($demand);
        }

        $signal = array_sum($signals) / count($signals);

        return [
            $this->toModifier($signal),
            $this->reason('reservoir_and_demand', '水庫蓄水相對指標與園區需求相對指標的平均訊號', [
                'storage_index_mean' => $storage,
                'demand_index_mean' => $demand,
                'ratio_saturation' => $this->ratioSaturation,
            ]),
        ];
    }

    /**
     * 熱系＝建成地佔比在 13 鄉鎮市分布中的位置。
     *
     * 建成地佔比越高，城市熱系防線越吃力，玩家越好打（正值）。
     * 這是土地利用調查的代理指標，不是熱島強度量測。
     *
     * @param  array<string, NormalizedSnapshot>  $snapshots
     * @return array{0: float, 1: array{code: string, message: string, inputs: array<string, mixed>}}
     */
    private function heat(array $snapshots): array
    {
        return $this->landUseSignal(
            $snapshots['moi.land_use'] ?? null,
            fn (array $metrics): ?float => $metrics['built_up_ratio'] ?? null,
            direction: 1,
            code: 'built_up_share',
            message: '全縣建成地佔比在 13 鄉鎮市分布中的位置（土地利用代理指標，非熱島量測）',
        );
    }

    /**
     * 土地系＝森林加公園綠地佔比在 13 鄉鎮市分布中的位置。
     *
     * 綠地佔比越高，城市土地防線越穩，玩家越難打（負值）。
     *
     * @param  array<string, NormalizedSnapshot>  $snapshots
     * @return array{0: float, 1: array{code: string, message: string, inputs: array<string, mixed>}}
     */
    private function land(array $snapshots): array
    {
        return $this->landUseSignal(
            $snapshots['moi.land_use'] ?? null,
            function (array $metrics): ?float {
                $forest = $metrics['forest_ratio'] ?? null;
                $park = $metrics['park_green_ratio'] ?? null;

                return $forest === null || $park === null ? null : $forest + $park;
            },
            direction: -1,
            code: 'green_share',
            message: '全縣森林與公園綠地佔比在 13 鄉鎮市分布中的位置',
        );
    }

    /**
     * @param  callable(array<string, mixed>): ?float  $extract
     * @return array{0: float, 1: array{code: string, message: string, inputs: array<string, mixed>}}
     */
    private function landUseSignal(
        ?NormalizedSnapshot $snapshot,
        callable $extract,
        int $direction,
        string $code,
        string $message,
    ): array {
        if ($snapshot === null) {
            return [0.0, $this->reason('missing_inputs', '沒有國土利用快照，採中性修正', [])];
        }

        $county = $snapshot->metrics['_county'] ?? null;
        $countyValue = $county === null ? null : $extract($county);

        $townshipValues = [];

        foreach ($snapshot->metrics as $key => $metrics) {
            if ($key === '_county') {
                continue;
            }

            $value = $extract($metrics);

            if ($value !== null) {
                $townshipValues[] = $value;
            }
        }

        if ($countyValue === null || count($townshipValues) < 2) {
            return [0.0, $this->reason('missing_inputs', '國土利用指標缺值，採中性修正', [])];
        }

        $min = min($townshipValues);
        $max = max($townshipValues);

        if ($max - $min <= 0.0) {
            return [0.0, $this->reason('no_spread', '13 鄉鎮市的指標沒有分布範圍，採中性修正', [])];
        }

        // 把觀測到的分布映射到 [-1, 1]，再乘方向與上限。
        $signal = 2.0 * (($countyValue - $min) / ($max - $min)) - 1.0;

        return [
            $this->toModifier($direction * $signal),
            $this->reason($code, $message, [
                'county_value' => round($countyValue, 6),
                'township_min' => round($min, 6),
                'township_max' => round($max, 6),
                'township_count' => count($townshipValues),
                'direction' => $direction,
            ]),
        ];
    }

    /**
     * 相對指標以 1.0 為中心；偏離達到 ratio_saturation 即訊號飽和。
     */
    private function ratioSignal(float $ratio): float
    {
        return max(-1.0, min(1.0, ($ratio - 1.0) / $this->ratioSaturation));
    }

    private function toModifier(float $signal): float
    {
        $signal = max(-1.0, min(1.0, $signal));

        return round($signal * $this->modifierLimit, 4);
    }

    private function meanRatio(?NormalizedSnapshot $snapshot, string $key): ?float
    {
        if ($snapshot === null) {
            return null;
        }

        $values = [];

        foreach ($snapshot->metrics as $metrics) {
            if (isset($metrics[$key]) && is_numeric($metrics[$key])) {
                $values[] = (float) $metrics[$key];
            }
        }

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * 只取標示 in_hsinchu 的園區。竹南、龍潭、銅鑼、宜蘭園區不在新竹行政區內，
     * 不能拿來當新竹的需求訊號。
     */
    private function meanHsinchuDemand(?NormalizedSnapshot $snapshot): ?float
    {
        if ($snapshot === null) {
            return null;
        }

        $values = [];

        foreach ($snapshot->metrics as $metrics) {
            if (($metrics['in_hsinchu'] ?? false) !== true) {
                continue;
            }

            if (isset($metrics['demand_index']) && is_numeric($metrics['demand_index'])) {
                $values[] = (float) $metrics['demand_index'];
            }
        }

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{code: string, message: string, inputs: array<string, mixed>}
     */
    private function reason(string $code, string $message, array $inputs): array
    {
        return ['code' => $code, 'message' => $message, 'inputs' => $inputs];
    }
}
