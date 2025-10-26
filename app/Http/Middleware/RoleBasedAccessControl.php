<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleBasedAccessControl
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user) {
            return redirect()->route('login');
        }

        // Apply role-based access control
        $this->applyRoleBasedRestrictions($request, $user);

        return $next($request);
    }

    /**
     * Apply role-based restrictions to the request
     */
    protected function applyRoleBasedRestrictions(Request $request, $user): void
    {
        // Skip domain restriction for domain routes (they already have proper model binding)
        if (!$request->route('domain')) {
            if ($user->hasDomainRestriction()) {
                $this->applyDomainRestriction($request, $user);
            }
        }

        // Apply location restrictions
        if ($user->hasLocationRestriction()) {
            $this->applyLocationRestriction($request, $user);
        }
    }

    /**
     * Apply domain restriction based on user's role level
     */
    protected function applyDomainRestriction(Request $request, $user): void
    {
        // Admin and below are restricted to their domain
        if ($user->role_level > 1) {
            $effectiveDomain = $user->getEffectiveDomain($request->route('domain'));
            
            // Only override if we're not in a domain route and the domain is different
            if (!$request->route('domain') && $request->route('domain') !== $effectiveDomain) {
                $request->route()->setParameter('domain', $effectiveDomain);
            }
        }
    }

    /**
     * Apply location restriction based on user's role level
     */
    protected function applyLocationRestriction(Request $request, $user): void
    {
        // Manager and below are restricted to their assigned location
        if ($user->role_level >= 3) {
            $effectiveLocationId = $user->getEffectiveLocationId($request->input('location_id'));
            
            // Override the location_id parameter
            $request->merge(['location_id' => $effectiveLocationId]);
        }
    }
}
