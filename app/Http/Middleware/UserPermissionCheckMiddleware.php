<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UserPermissionCheckMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Redirect unauthenticated users to login
        if (!$user) {
            return redirect()->route('login');
        }

        // Super user has unrestricted access
        if (method_exists($user, 'isSuperUser') && $user->isSuperUser()) {
            return $next($request);
        }

        // Route name
        $routeName = $request->route()?->getName();

        // Domain routes: allow if user belongs to the domain or has explicit permission
        if (str_starts_with($routeName, 'domains.')) {
            // Route domain slug from {domain:name_slug}
            $routeDomain = $request->route('domain');

            // Extract domain slug - handle both string and model
            $routeDomainSlug = is_string($routeDomain) ? $routeDomain : $routeDomain->name_slug;

            // Get user's domain (string column)
            $userDomainSlug = $user->domain;

            if ($routeDomainSlug && $userDomainSlug && $routeDomainSlug === $userDomainSlug) {

                return $next($request);
            }

            // Fallback: explicit permission to named route
            $permissions = $user->getAllPermissions();
            $hasPermission = collect($permissions)->contains('route_name', $routeName);
            if ($hasPermission) {

                return $next($request);
            }

            return $this->unauthorizedResponse($request);
        }

        // Non-domain routes: require explicit permission by route name
        $permissions = $user->getAllPermissions();
        $hasPermission = collect($permissions)->contains('route_name', $routeName);


        if (!$hasPermission) {
            return $this->unauthorizedResponse($request);
        }

        return $next($request);
    }

    /**
     * Handle unauthorized access.
     */
    private function unauthorizedResponse(Request $request): Response
    {
        // API request → JSON error
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You do not have permission to access this resource.',
                'error' => 'Forbidden',
            ], 403);
        }

        // Web request → redirect back with flash message
        return redirect()
            ->back()
            ->with('error', 'You do not have permission to access this page.');
    }
}
