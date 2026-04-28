<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On NativePHP, the global /dashboard is not for super users (blocked at login)
 * or for users without an org; organization users are sent to the domain dashboard.
 */
class RedirectNativeGlobalDashboardToDomain
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('nativephp-internal.running')) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->isSuperUser()) {
            abort(403, 'The desktop app is for organization accounts. Use the web app for administrator access.');
        }

        if ($user->domain) {
            return redirect()->route('domains.dashboard', ['domain' => $user->domain]);
        }

        abort(403, 'No organization context for this account in the desktop app.');
    }
}
