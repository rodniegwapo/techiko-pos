<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
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
        // Set up morph map for polymorphic relationships
        Relation::morphMap([
            'Order' => 'App\Models\Sale',
            'Sale' => 'App\Models\Sale',
            'StockAdjustment' => 'App\Models\StockAdjustment',
        ]);
    }
}
