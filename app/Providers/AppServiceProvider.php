<?php

namespace App\Providers;

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
