<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Tests;

use Afiqsazlan\SafeSql\SafeSqlServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SafeSqlServiceProvider::class,
        ];
    }

    /**
     * The package targets MySQL, but the query validator is pure string
     * analysis and never reaches a connection. SQLite keeps those tests fast;
     * anything that actually executes SQL belongs in a MySQL-backed
     * integration test instead.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
