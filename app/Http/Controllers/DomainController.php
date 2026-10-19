<?php

namespace App\Http\Controllers;

use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Models\ServiceTier;
use App\Services\Ai\AiCreditService;
use App\Services\DomainService;
use App\Services\DomainSubscriptionService;
use App\Services\SubscriptionPaymentService;
use App\Support\FreePlan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DomainController extends Controller
{
    public function __construct(
        private DomainService $domainService,
        private DomainSubscriptionService $subscriptions,
        private AiCreditService $aiCredits,
        private SubscriptionPaymentService $payments,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $currentUser = auth()->user();
        $domains = $this->domainService->getFilteredDomains($request, $currentUser);

        return Inertia::render('Domains/Index', [
            'domains' => DomainResource::collection($domains),
            'timezones' => $this->domainService->getAvailableTimezones(),
            'currencies' => $this->domainService->getAvailableCurrencies(),
            'countries' => $this->domainService->getAvailableCountries(),
            'isGlobalView' => true,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Domains/Create', [
            'timezones' => $this->domainService->getAvailableTimezones(),
            'currencies' => $this->domainService->getAvailableCurrencies(),
            'countries' => $this->domainService->getAvailableCountries(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->domainService->getCreationValidationRules());

        $domain = $this->domainService->createDomain($validated);

        return redirect()->route('domains.index', $domain)
            ->with('success', 'Domain created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Domain $domain)
    {
        $domain->load(['users', 'currentServiceTier']);

        return Inertia::render('Domains/Show', [
            'domain' => new DomainResource($domain),
            // Plan & AI credits, for super admins (the section is hidden from others).
            'billing' => fn () => request()->user()?->isSuperUser() ? [
                'tiers' => ServiceTier::query()->active()->get(['id', 'name', 'amount', 'ai_monthly_credits']),
                'service_tier_id' => $domain->current_service_tier_id,
                'subscription_started_at' => $domain->subscription_started_at?->toDateString(),
                'subscription_ends_at' => $domain->subscription_ends_at?->toDateString(),
                'locked_at' => $domain->locked_at?->toIso8601String(),
                'vip_at' => $domain->vip_at?->toIso8601String(),
                'next_payment' => $this->payments->nextPayment($domain),
                'payments' => $this->payments->history($domain),
                'ai_monthly_credits_override' => $domain->ai_monthly_credits_override,
                'free_credits' => FreePlan::aiCredits(),
                'ai_credits' => $this->aiCredits->propsForFrontend($domain),
                'ai_cost_this_month_usd' => $this->aiCredits->costThisMonth($domain),
                'ai_usage_url' => route('domains.ai-usage.index', ['domain' => $domain->name_slug]),
            ] : null,
        ]);
    }

    /**
     * Set an organization's plan (null = free), when its subscription started and ends, and,
     * optionally, its own monthly AI credits instead of the plan's. Super admins only.
     */
    public function updateSubscription(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'service_tier_id' => ['nullable', 'integer', Rule::exists('service_tiers', 'id')->where('is_active', true)],
            'ai_monthly_credits_override' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'subscription_started_at' => ['nullable', 'date'],
            'subscription_ends_at' => ['nullable', 'date'],
        ]);

        $tierId = $validated['service_tier_id'] ?? null;
        if ($tierId === null) {
            // The free plan has no subscription dates.
            $this->subscriptions->clearServicingTier($domain);
        } elseif ((int) $tierId !== (int) $domain->current_service_tier_id) {
            // Only a change of plan restarts the subscription date.
            $this->subscriptions->grantServicingTier($domain->name_slug, (int) $tierId);
        }

        $domain->refresh();
        $changes = ['ai_monthly_credits_override' => $validated['ai_monthly_credits_override'] ?? null];
        if ($tierId !== null) {
            // Dates the super admin typed win over "started today" from a plan change.
            if (! empty($validated['subscription_started_at'])) {
                $changes['subscription_started_at'] = Carbon::parse($validated['subscription_started_at'])->startOfDay();
            }
            if (array_key_exists('subscription_ends_at', $validated)) {
                $changes['subscription_ends_at'] = $validated['subscription_ends_at'];
            }

            $start = $changes['subscription_started_at'] ?? $domain->subscription_started_at;
            $end = array_key_exists('subscription_ends_at', $changes) ? $changes['subscription_ends_at'] : $domain->subscription_ends_at;
            if ($start && $end && Carbon::parse($end)->lt(Carbon::parse($start)->startOfDay())) {
                throw ValidationException::withMessages(['subscription_ends_at' => 'The end date must be on or after the start date.']);
            }
        }
        $domain->update($changes);

        return back()->with('success', 'Plan updated.');
    }

    /** Locks an organization that hasn't paid (its users only see the "on hold" page), or unlocks it. Super admins only. */
    public function updateLock(Request $request, Domain $domain)
    {
        $locked = $request->validate(['locked' => ['required', 'boolean']])['locked'];

        if ($locked && $domain->isVip()) {
            throw ValidationException::withMessages(['locked' => 'A VIP organization can\'t be locked. Turn VIP off first.']);
        }

        $domain->update(['locked_at' => $locked ? ($domain->locked_at ?? now()) : null]);

        return back()->with('success', $locked ? "{$domain->name} is locked. Its users now see the on-hold page." : "{$domain->name} is unlocked.");
    }

    /**
     * Makes an organization VIP (every premium feature and limit, unlimited AI credits, no
     * subscription warnings, never locked, nothing to pay), or ends it. Super admins only.
     */
    public function updateVip(Request $request, Domain $domain)
    {
        $vip = $request->validate(['vip' => ['required', 'boolean']])['vip'];

        $domain->update($vip
            ? ['vip_at' => $domain->vip_at ?? now(), 'locked_at' => null]
            : ['vip_at' => null]);

        return back()->with('success', $vip
            ? "{$domain->name} is now VIP: every feature, no limits, nothing to pay."
            : "{$domain->name} is no longer VIP. Its plan applies again.");
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Domain $domain)
    {
        return Inertia::render('Domains/Edit', [
            'domain' => new DomainResource($domain),
            'timezones' => $this->domainService->getAvailableTimezones(),
            'currencies' => $this->domainService->getAvailableCurrencies(),
            'countries' => $this->domainService->getAvailableCountries(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Domain $domain)
    {
        $validated = $request->validate($this->domainService->getUpdateValidationRules($domain));

        $this->domainService->updateDomain($domain, $validated);

        return redirect()->route('domains.index', $domain)
            ->with('success', 'Domain updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Domain $domain)
    {
        $this->domainService->deleteDomain($domain);

        return redirect()->route('domains.index')
            ->with('success', 'Domain deleted successfully.');
    }

    /**
     * Turn Finance (with its AI explanations) on or off for an organization, outside its plan. The AI
     * assistant comes with every plan (a 0-credit override turns it off). Other settings are kept.
     */
    public function updateFeatures(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'finance' => ['required', 'boolean'],
        ]);

        $settings = $domain->settings ?? [];
        $settings['features'] = ['finance' => (bool) $validated['finance']];
        $domain->update(['settings' => $settings]);

        return back()->with('success', 'Features updated.');
    }

    /**
     * Toggle domain active status
     */
    public function toggleStatus(Request $request, Domain $domain)
    {
        // Toggle the is_active field
        $domain->update([
            'is_active' => ! $domain->is_active,
        ]);

        // Prepare the success message based on the new state
        $status = $domain->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Domain '{$domain->name}' has been {$status}.");
    }
}
