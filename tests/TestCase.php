<?php

declare(strict_types=1);

namespace Kanvigo\Audit\Chronicle\Tests;

use Chronicle\ChronicleServiceProvider;
use Illuminate\Foundation\Application;
use Kanvigo\Audit\Chronicle\ChronicleAuditServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Chronicle's own provider plus the bridge. Chronicle is a dependency, so
     * testbench (which does not auto-discover a package's own dependencies) must
     * load it explicitly.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ChronicleServiceProvider::class,
            ChronicleAuditServiceProvider::class,
        ];
    }

    /**
     * Use an in-memory SQLite database by default; switch to PostgreSQL by
     * setting DB_CONNECTION=pgsql (plus the usual DB_* vars) so the suite can run
     * against both drivers — the append-only + hash-chain behaviour must hold on
     * the production driver, not only SQLite.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        if (env('DB_CONNECTION') === 'pgsql') {
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'prefix' => '',
                'search_path' => 'public',
            ]);

            return;
        }

        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    /**
     * Chronicle only publishes its migrations, so load them straight from the
     * installed package for the test database.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(
            dirname(__DIR__).'/vendor/laravel-chronicle/core/database/migrations',
        );
    }
}
