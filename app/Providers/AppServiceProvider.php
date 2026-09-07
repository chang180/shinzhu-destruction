<?php

namespace App\Providers;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifierCalculator;
use App\Domain\Game\SkillCatalog;
use App\Services\OpenData\FixtureRepository;
use App\Services\OpenData\OpenDataRegistry;
use App\Services\OpenData\Support\GuardedDownloader;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
        //
    }
}
