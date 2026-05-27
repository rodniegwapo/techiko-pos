<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits /desktop/* routes unless NativePHP, PHPUnit bootstrap flag, or local browser dev (APP_ENV=local).
 */
class EnsureDesktopRoutesAllowed
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('nativephp-internal.running')
            || filter_var(env('TEST_NATIVE_DESKTOP_ROUTES', false), FILTER_VALIDATE_BOOLEAN)
            || app()->environment('local');

        abort_unless($allowed, Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
