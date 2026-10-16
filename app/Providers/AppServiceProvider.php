<?php

namespace App\Providers;

use App\Http\Middleware\StartSessionUnlessRetired;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailedOver;

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
        // Every pipeline that starts a session resolves StartSession from the container — the web
        // group, api-session and Sanctum's stateful /api requests alike — so this covers them all.
        $this->app->singleton(StartSession::class, function ($app) {
            return new StartSessionUnlessRetired($app->make(SessionManager::class), function () use ($app) {
                return $app->make(CacheFactory::class);
            });
        });
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

        // An AI provider failed and the next one (services.*.fallback_provider) was tried.
        Event::listen(AgentFailedOver::class, fn (AgentFailedOver $e) => Log::warning('AI failed over to the next provider', [
            'agent' => class_basename($e->agent),
            'from' => $e->provider->name(),
            'model' => $e->model,
            'reason' => class_basename($e->exception).': '.$e->exception->getMessage(),
        ]));

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
