<?php

namespace App\Services\OpenData\Snapshot;

use Carbon\CarbonImmutable;

/**
 * 資料涵蓋期間。月／年度資料用它取代 observed_at，並保留原始曆別標示，
 * 避免民國年與西元年混用。
 */
final readonly class SnapshotPeriod
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $label,
        public string $calendar,
    ) {}

    /**
     * @return array{start: string, end: string, label: string, calendar: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->utc()->toIso8601String(),
            'end' => $this->end->utc()->toIso8601String(),
            'label' => $this->label,
            'calendar' => $this->calendar,
        ];
    }

    /**
     * @param  array{start: string, end: string, label: string, calendar: string}  $period
     */
    public static function fromArray(array $period): self
    {
        return new self(
            CarbonImmutable::parse($period['start'])->utc(),
            CarbonImmutable::parse($period['end'])->utc(),
            $period['label'],
            $period['calendar'],
        );
    }
}
