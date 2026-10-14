<?php

namespace App\Http\Controllers;

use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Models\ServiceTier;
use App\Services\Ai\AiCreditService;
use App\Services\DomainService;
use App\Services\DomainSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DomainController extends Controller
{
    public function __construct(
        private DomainService $domainService,
        private DomainSubscriptionService $subscriptions,
        private AiCreditService $aiCredits,
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
                'ai_monthly_credits_override' => $domain->ai_monthly_credits_override,
                'free_credits' => (int) config('ai_billing.free_monthly_credits', 30),
                'ai_credits' => $this->aiCredits->propsForFrontend($domain),
                'ai_cost_this_month_usd' => $this->aiCredits->costThisMonth($domain),
                'ai_usage_url' => route('domains.ai-usage.index', ['domain' => $domain->name_slug]),
            ] : null,
        ]);
    }

    /**
     * Set an organization's plan (null = free) and, optionally, its own monthly AI credits instead
     * of the plan's. Super admins only.
     */
    public function updateSubscription(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'service_tier_id' => ['nullable', 'integer', Rule::exists('service_tiers', 'id')->where('is_active', true)],
            'ai_monthly_credits_override' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $tierId = $validated['service_tier_id'] ?? null;
        if ($tierId === null) {
            $this->subscriptions->clearServicingTier($domain);
        } elseif ((int) $tierId !== (int) $domain->current_service_tier_id) {
            // Only a change of plan restarts the subscription date.
            $this->subscriptions->grantServicingTier($domain->name_slug, (int) $tierId);
        }

        $domain->refresh()->update(['ai_monthly_credits_override' => $validated['ai_monthly_credits_override'] ?? null]);

        return back()->with('success', 'Plan updated.');
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
     * Turn Finance (with its AI explanations) and the AI assistant on or off for an organization.
     * The two are independent. Other settings (e.g. sales) are kept.
     */
    public function updateFeatures(Request $request, Domain $domain)
    {
        $validated = $request->validate([
            'finance' => ['required', 'boolean'],
            'ai' => ['required', 'boolean'],
        ]);

        $finance = (bool) $validated['finance'];
        $settings = $domain->settings ?? [];
        $settings['features'] = [
            'finance' => $finance,
            'ai' => (bool) $validated['ai'],
        ];
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
