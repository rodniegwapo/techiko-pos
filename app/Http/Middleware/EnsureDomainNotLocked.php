<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a locked organization's pages closed (a super admin locked it for not paying): its users
 * can still log in, but only see the "on hold" page. Super admins are never locked out.
 */
class EnsureDomainNotLocked
{
    public const MESSAGE = "This organization's subscription is on hold. Contact us to renew.";

    /** Sales already rung up offline still upload, so none are lost while locked. */
    private const ALWAYS_OPEN = ['domains.sales.offline-sync'];

    public function handle(Request $request, Closure $next): Response
    {
        $routeDomain = $request->route('domain');
        $domain = $routeDomain instanceof Domain
            ? $routeDomain
            : Domain::query()->where('name_slug', $routeDomain)->first();

        if (! $domain?->isLocked()
            || $request->user()?->isSuperUser()
            || in_array($request->route()?->getName(), self::ALWAYS_OPEN, true)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => self::MESSAGE, 'subscription_on_hold' => true], 423);
        }

        return redirect()->route('domains.subscription-on-hold', ['domain' => $domain->name_slug]);
    }
}
