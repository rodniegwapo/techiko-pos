<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SetDomainFromRoute
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $domainSlug = $request->route('domain');
        
        if (!$domainSlug) {
            abort(404, 'Domain not found');
        }
        
        $domain = Domain::where('name_slug', $domainSlug)
                       ->where('is_active', true)
                       ->first();
        
        if (!$domain) {
            abort(404, 'Store not found');
        }
        
        // Check if user has access to this domain
        $user = auth()->user();
        if ($user && !$user->isSuperUser() && $user->domain_id && $user->domain_id !== $domain->id) {
            abort(403, 'You do not have access to this domain');
        }
        
        // Set domain context
        app()->instance('domain', $domain);
        
        // Set timezone for the request
        config(['app.timezone' => $domain->timezone]);
        
        // Share domain with Inertia
        Inertia::share('domain', $domain);
        
        return $next($request);
    }
}