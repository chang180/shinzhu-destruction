<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一次已結算的行動及其事件序列。重送同一個 action_id 時直接回放這一列，
 * 不重新結算，所以重複提交永遠不會多扣資源。
 *
 * @property list<array<string, mixed>> $events
 * @property array<string, mixed> $input
 * @property array<string, mixed> $state_after
 */
class RunAction extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'events' => 'array',
            'state_after' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
