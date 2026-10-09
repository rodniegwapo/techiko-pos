<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->validateCsrfTokens(except: [
            'webhooks/paymongo',
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->group('api-session', [
            \Illuminate\Session\Middleware\StartSession::class,
        ]);

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'signed' => \App\Http\Middleware\ValidateSignature::class,

            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

            'check.permission' => \App\Http\Middleware\CheckPermission::class,
            'check.super.user' => \App\Http\Middleware\CheckSuperUser::class,
            'user.permission' => \App\Http\Middleware\UserPermissionCheckMiddleware::class,
            'role.access' => \App\Http\Middleware\RoleBasedAccessControl::class,
            'location.sellable' => \App\Http\Middleware\EnsureSellableLocation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Expired session / CSRF token (419): send the user somewhere useful instead of the raw "Page Expired" screen.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 419 || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return $response;
            }

            if (Auth::check()) {
                return back()->with('error', 'The page expired. Please try again.');
            }

            return redirect()->route('login')->with('status', 'Your session expired. Please log in again.');
        });
    })->create();
