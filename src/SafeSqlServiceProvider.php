<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql;

use Afiqsazlan\SafeSql\Console\ClassifyColumnsCommand;
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

        $this->commands([
            ClassifyColumnsCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/safe-sql.php' => config_path('safe-sql.php'),
        ], 'safe-sql-config');

        $this->publishes([
            __DIR__.'/../routes/safe-sql.php' => base_path('routes/safe-sql.php'),
        ], 'safe-sql-routes');

        $this->publishes([
            __DIR__.'/../stubs/ResearchServer.php.stub' => app_path('Mcp/Servers/ResearchServer.php'),
        ], 'safe-sql-server');
    }
}
