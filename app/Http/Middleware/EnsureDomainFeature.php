<?php

namespace App\Http\Middleware;

use App\Helpers;
use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a feature's pages and endpoints closed to an organization that hasn't been given it
 * (see Domain::hasFeature). Used as `domain.feature:finance` or `domain.feature:ai`.
 */
class EnsureDomainFeature
{
    public const MESSAGES = [
        'finance' => "Finance isn't turned on for this organization.",
        'ai' => "AI support isn't turned on for this organization.",
    ];

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $routeDomain = $request->route('domain');
        $domain = $routeDomain instanceof Domain
            ? $routeDomain
            : Domain::query()->where('name_slug', $routeDomain)->first();

        if (! $domain || $domain->hasFeature($feature)) {
            return $next($request);
        }

        $message = self::MESSAGES[$feature] ?? "This feature isn't turned on for this organization.";

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        $user = $request->user();
        $route = $user
            ? Helpers::homeRouteFor($user, Helpers::getActiveLocation($domain))
            : 'login';

        return redirect()
            ->route($route, $route === 'login' || $route === 'profile.edit' ? [] : ['domain' => $domain->name_slug])
            ->with('notice', $message);
    }
}
