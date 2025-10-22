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

        // Get domain (likely from route model binding)
        $domain = data_get($request, 'domain');

        // Ensure domain exists
        if (!data_get($domain, 'name_slug')) {
            return $this->unauthorizedResponse($request);
        }

        // Ensure domain matches user's assigned domain (unless super user)
        if (data_get($domain, 'name_slug') !== $user->domain && !$user->isSuperUser()) {
            return $this->unauthorizedResponse($request);
        }

        // Route name for permission checking
        $routeName = $request->route()?->getName();

        // Check route permission
        if (!$this->hasRoutePermission($user, $routeName)) {
            return $this->unauthorizedResponse($request);
        }

        return $next($request);
    }

    /**
     * Check if user has permission for the given route using route normalization.
     */
    private function hasRoutePermission($user, $routeName): bool
    {
        // Super users always have access
        if (method_exists($user, 'isSuperUser') && $user->isSuperUser()) {
            return true;
        }

        // Normalize domain routes to base routes for permission matching
        $permissionRoute = $this->normalizeRouteForPermission($routeName);

        $permissions = $user->getAllPermissions();

        return $permissions->contains('route_name', $permissionRoute);
    }

    /**
     * Normalize route names for permission matching.
     * Converts domain routes to base routes for consistent permission checking.
     */
    private function normalizeRouteForPermission(?string $routeName): string
    {
        if (!$routeName) {
            return '';
        }

        // Convert 'domains.products.index' → 'products.index'
        if (str_starts_with($routeName, 'domains.')) {
            return str_replace('domains.', '', $routeName);
        }

        return $routeName;
    }

    /**
     * Handle unauthorized access.
     */
    private function unauthorizedResponse(Request $request): Response
    {
        // API request → JSON response
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You do not have permission to access this resource.',
                'error' => 'Forbidden',
            ], 403);
        }

        // Web request → redirect with flash message
        return redirect()
            ->back()
            ->with('error', 'You do not have permission to access this page.');
    }
}
