<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * 匿名玩家的戰役進度。只保存是否通關與最佳表現，不用永久增傷代替策略。
 *
 * @property string $anonymous_id
 * @property list<string> $unlocked
 * @property array<string, mixed> $best_results
 */
class Campaign extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unlocked' => 'array',
            'best_results' => 'array',
            'last_activity_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * 不可猜測的匿名識別。它只存在於 HttpOnly 工作階段，不出現在網址或回應。
     */
    public static function newAnonymousId(): string
    {
        return Str::random(48);
    }

    public function hasCleared(string $levelId): bool
    {
        return array_key_exists($levelId, $this->best_results);
    }
}
