<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql;

use Illuminate\Support\ServiceProvider;

class SafeSqlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/safe-sql.php', 'safe-sql');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/safe-sql.php' => config_path('safe-sql.php'),
        ], 'safe-sql-config');
    }
}
