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
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();

        // Super user has all permissions
        if ($user->isSuperUser()) {
            return $next($request);
        }


        $permissions = auth()->user()->getAllPermissions();
        $find = collect($permissions)->where('name', $request->route()?->getName())->first();

        if (!$find) {
            return $this->handleUnauthorized($request);
        }

        return $next($request);
    }

    private function handleUnauthorized(Request $request): Response
    {
        // If it's an API request, return JSON error
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You do not have permission to access this resource.',
                'error' => 'Forbidden'
            ], 403);
        }

        // For web requests, redirect with error message
        return redirect()->back()->with('error', 'You do not have permission to access this page.');
    }
}
