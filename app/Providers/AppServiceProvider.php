<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Path users are redirected to after authentication.
     */
    public const HOME = '/dashboard';

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
        Relation::morphMap([
            'Order' => 'App\Models\Sale',
            'Sale' => 'App\Models\Sale',
            'StockAdjustment' => 'App\Models\StockAdjustment',
            'Purchase' => 'App\Models\Purchase',
        ]);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('register-page', function (Request $request) {
            $max = max(1, (int) config('registration.throttle.page_per_minute', 60));

            return Limit::perMinute($max)->by($request->ip());
        });

        RateLimiter::for('register-submit', function (Request $request) {
            $max = max(1, (int) config('registration.throttle.submit_per_minute', 10));

            return Limit::perMinute($max)->by($request->ip());
        });

        Route::bind('sale', function ($value, $route) {
            $domain = $route->parameter('domain');

            if ($domain) {
                return \App\Models\Sale::where('id', $value)
                    ->where('domain', $domain)
                    ->firstOrFail();
            }

            return \App\Models\Sale::findOrFail($value);
        });
    }
}
