<?php

namespace App\Models;

use App\Domain\Game\BattleState;
use App\Domain\Game\Outcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 一場對局。開局時綁定 rules_version 與快照組，之後兩者都不再改變：
 * 同一局中途不換資料，也不用新規則重算舊局。
 *
 * @property string $public_id
 * @property array<string, mixed> $state
 * @property array<string, string> $snapshot_ids
 * @property array<string, mixed> $scenario_modifiers
 * @property Outcome $outcome
 */
class Run extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => 'array',
            'snapshot_ids' => 'array',
            'snapshot_metadata' => 'array',
            'scenario_modifiers' => 'array',
            'outcome' => Outcome::class,
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return HasMany<RunAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(RunAction::class)->orderBy('sequence');
    }

    public function battleState(): BattleState
    {
        return BattleState::fromArray($this->state);
    }
}
