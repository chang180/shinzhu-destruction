<?php

namespace App\Models;

use App\Services\OpenData\Snapshot\NormalizedSnapshot;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $snapshot_id
 * @property string $source_id
 * @property SnapshotQuality $quality
 * @property array<string, mixed> $payload
 * @property bool $is_current
 */
class DataSnapshot extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quality' => SnapshotQuality::class,
            'payload' => 'array',
            'fetched_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'is_current' => 'boolean',
        ];
    }

    /**
     * @param  Builder<DataSnapshot>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    public function toNormalizedSnapshot(): NormalizedSnapshot
    {
        return NormalizedSnapshot::fromArray($this->payload);
    }
}
