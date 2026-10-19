<?php

namespace App\Http\Middleware;

use App\Helpers;
use App\Models\Domain;
use App\Models\ServiceTier;
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
        'ai' => "The AI assistant isn't turned on for this organization.",
        'expenses' => "Expenses aren't part of this organization's plan.",
        'sales_history_unlimited' => "Full sales history and CSV export aren't part of this organization's plan.",
        'advanced_inventory' => "Stock movements, valuation and transfers aren't part of this organization's plan.",
        'multi_location' => "More than one location isn't part of this organization's plan.",
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
        if ($plan = self::cheapestPlanWith($feature)) {
            $message .= " It comes with the {$plan->name} plan.";
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 403);
        }

        $user = $request->user();
        // Someone who can pay for the organization goes to the plans; anyone else back home.
        $route = match (true) {
            ! $user => 'login',
            $plan !== null && $user->hasPermissionToRoute('billing.servicing.index') => 'domains.billing.servicing.index',
            default => Helpers::homeRouteFor($user, Helpers::getActiveLocation($domain)),
        };

        return redirect()
            ->route($route, $route === 'login' || $route === 'profile.edit' ? [] : ['domain' => $domain->name_slug])
            ->with('notice', $message);
    }

    /** The cheapest plan an organization can pay for that includes the feature (none while plans aren't enforced). */
    public static function cheapestPlanWith(string $feature): ?ServiceTier
    {
        if (! config('features.plan_gating', false)) {
            return null;
        }

        return ServiceTier::query()
            ->where('is_active', true)
            ->where('shown_on_billing', true)
            ->whereJsonContains('features', $feature)
            ->orderBy('amount')
            ->first();
    }
}
