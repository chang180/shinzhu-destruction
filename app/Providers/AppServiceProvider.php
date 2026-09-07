<?php

namespace App\Providers;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifierCalculator;
use App\Domain\Game\SkillCatalog;
use App\Services\Game\CampaignResolver;
use App\Services\OpenData\FixtureRepository;
use App\Services\OpenData\OpenDataRegistry;
use App\Services\OpenData\Support\GuardedDownloader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GuardedDownloader::class, function ($app): GuardedDownloader {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new GuardedDownloader($config->get('opendata.user_agent'));
        });

        $this->app->singleton(OpenDataRegistry::class, function ($app): OpenDataRegistry {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new OpenDataRegistry(
                $config->get('opendata.sources'),
                $config->get('opendata.schema_version'),
                $app->make(GuardedDownloader::class),
            );
        });

        $this->app->singleton(SkillCatalog::class, function ($app): SkillCatalog {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new SkillCatalog($config->get('game.skills'));
        });

        $this->app->singleton(LevelRepository::class, function ($app): LevelRepository {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new LevelRepository($config->get('game.levels'));
        });

        $this->app->singleton(ScenarioModifierCalculator::class, function ($app): ScenarioModifierCalculator {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new ScenarioModifierCalculator(
                $config->get('game.scenario.ratio_saturation'),
                $config->get('game.scenario.modifier_limit'),
            );
        });

        // 引擎在單次結算內累積事件序列，因此每次解析都給新實例。
        $this->app->bind(BattleEngine::class, function ($app): BattleEngine {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new BattleEngine($app->make(SkillCatalog::class), $config->get('game'));
        });

        $this->app->singleton(FixtureRepository::class, function ($app): FixtureRepository {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new FixtureRepository($config->get('opendata.demo_fixture_path'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * 以匿名玩家為單位限流，不是以 IP：同一個 NAT 後面的玩家不該互相拖累。
         *
         * key 用工作階段裡的匿名識別而不是 session id——session id 會在某些情境下
         * 每次請求重新產生，那樣等於沒有限流。第一次請求還沒有識別時退回 IP。
         */
        RateLimiter::for('game-actions', fn (Request $request): Limit => Limit::perMinute(60)->by(
            $request->hasSession()
                ? (string) ($request->session()->get(CampaignResolver::SESSION_KEY) ?: $request->ip())
                : (string) $request->ip()
        ));
    }
}
