<?php

namespace App\Services\Game;

use App\Domain\Game\LevelRepository;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 把 HttpOnly 工作階段對應到一份匿名戰役進度。
 *
 * 沒有登入、沒有跨裝置帳號；cookie 遺失就是新的匿名玩家，不承諾找回舊進度。
 */
class CampaignResolver
{
    public const SESSION_KEY = 'campaign_anonymous_id';

    public function __construct(private readonly LevelRepository $levels) {}

    /**
     * 只查，不建立。唯讀端點用這個——公開的關卡列表不該讓每個沒有 cookie 的
     * 請求都在資料庫留下一列，那樣爬蟲打幾次就能無上限灌資料。
     */
    public function existing(Request $request): ?Campaign
    {
        if (! $request->hasSession()) {
            return null;
        }

        $anonymousId = $request->session()->get(self::SESSION_KEY);

        if (! is_string($anonymousId)) {
            return null;
        }

        return Campaign::query()->where('anonymous_id', $anonymousId)->first();
    }

    /**
     * 查不到就建立。只有玩家真的開始一局時才呼叫。
     */
    public function resolve(Request $request): Campaign
    {
        $campaign = $this->existing($request);

        if ($campaign !== null) {
            return $campaign;
        }

        $campaign = Campaign::query()->create([
            'anonymous_id' => Campaign::newAnonymousId(),
            'unlocked' => $this->initiallyUnlocked(),
            'best_results' => [],
            'last_activity_at' => Carbon::now(),
        ]);

        $request->session()->put(self::SESSION_KEY, $campaign->anonymous_id);

        return $campaign;
    }

    /**
     * 沒有前置關卡的關卡一開始就解鎖。尚未建立戰役的訪客看到的也是這一組。
     *
     * @return list<string>
     */
    public function initiallyUnlocked(): array
    {
        $unlocked = [];

        foreach ($this->levels->all() as $levelId => $level) {
            if ($level->requires === null) {
                $unlocked[] = $levelId;
            }
        }

        return $unlocked;
    }
}
