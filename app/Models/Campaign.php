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
            'practice_unlocked' => 'array',
            'practice_results' => 'array',
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

    /**
     * 練習軌的解鎖狀態。舊戰役沒有這一欄，回退到挑戰軌的初始解鎖由呼叫端決定，
     * 這裡只保證型別，不把 null 當成「什麼都沒解鎖」以外的意思。
     *
     * @return list<string>
     */
    public function practiceUnlocked(): array
    {
        return $this->practice_unlocked ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function practiceResults(): array
    {
        return $this->practice_results ?? [];
    }

    public function hasCleared(string $levelId): bool
    {
        return array_key_exists($levelId, $this->best_results);
    }
}
