<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;

/**
 * Migrates the test database once per run as the schema owner, then wraps
 * each test in a transaction on the app connection. Tests therefore run as
 * the same restricted role as production, with row-level security on.
 */
trait RefreshesTenantDatabase
{
    use DatabaseTransactions;

    private static bool $migrated = false;

    protected function setUpRefreshesTenantDatabase(): void
    {
        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_migrator', '--force' => true]);
            self::$migrated = true;
        }
    }
}
