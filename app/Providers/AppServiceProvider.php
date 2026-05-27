<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $offlineDb = config('database.connections.offline_sqlite.database');
        if (is_string($offlineDb) && $offlineDb !== '' && ! str_contains($offlineDb, ':memory:')) {
            File::ensureDirectoryExists(dirname($offlineDb));
        }

        $this->pinSessionDatabaseConnectionForRuntimeDbSwitch();

        if (empty(config('licensing-client.server_url')) || config('licensing-client.server_url') === 'https://licensing.example.com') {
            config(['licensing-client.server_url' => rtrim((string) config('app.url'), '/')]);
        }

        // Set up morph map for polymorphic relationships
        Relation::morphMap([
            'Order' => 'App\Models\Sale',
            'Sale' => 'App\Models\Sale',
            'StockAdjustment' => 'App\Models\StockAdjustment',
            'Purchase' => 'App\Models\Purchase',
        ]);
    }

    /**
     * When the runtime DB switch runs, default connection flips after StartSession; database-backed
     * sessions with no SESSION_CONNECTION would read/write on different connections → CSRF 419 on
     * POST /login. Pin to the stable online connection unless the app explicitly sets SESSION_CONNECTION.
     */
    private function pinSessionDatabaseConnectionForRuntimeDbSwitch(): void
    {
        if (! config('runtime_database.enabled')
            || (string) config('session.driver') !== 'database') {
            return;
        }

        $connection = config('session.connection');
        if ($connection !== null && $connection !== '') {
            return;
        }

        config(['session.connection' => config('runtime_database.online_connection')]);
    }
}
