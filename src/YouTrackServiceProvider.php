<?php

declare(strict_types=1);

namespace E98\YouTrack;

use E98\YouTrack\Console\PingCommand;
use Illuminate\Support\ServiceProvider;

class YouTrackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/youtrack.php', 'youtrack');

        $this->app->singleton(
            YouTrackClient::class,
            fn ($app): YouTrackClient => new YouTrackClient((array) $app['config']->get('youtrack', [])),
        );

        $this->app->alias(YouTrackClient::class, 'youtrack');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/youtrack.php' => config_path('youtrack.php'),
            ], 'youtrack-config');

            $this->commands([PingCommand::class]);
        }
    }
}
