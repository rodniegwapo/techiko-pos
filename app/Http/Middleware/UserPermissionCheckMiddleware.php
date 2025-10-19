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

        // Temporarily bypass permission check for domain routes
        $routeName = $request->route()?->getName();
        if (str_starts_with($routeName, 'domains.')) {
            return $next($request);
        }

        // Retrieve permissions once
        $permissions = $user->getAllPermissions();

        // Try to match route name
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
