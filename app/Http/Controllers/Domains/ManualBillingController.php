<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ManualPaymentRequest;
use App\Models\ServiceTier;
use App\Services\DomainSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ManualBillingController extends Controller
{
    public function __construct(
        private DomainSubscriptionService $subscriptionService
    ) {}

    public function index(Request $request, Domain $domain)
    {
        $domain->load('currentServiceTier');
        $tiers = ServiceTier::query()
            ->active()
            ->get()
            ->reject(fn (ServiceTier $tier) => ServiceTier::isHiddenFromBilling($tier))
            ->values();

        $basicSlug = strtolower((string) config('manual_billing.servicing_basic_tier_slug'));

        $tierRows = $tiers->map(function (ServiceTier $tier) use ($basicSlug): array {
            $slug = strtolower((string) $tier->slug);

            return array_merge($tier->toArray(), [
                'uses_vite_bundle_qrph' => $basicSlug !== '' && $slug === $basicSlug,
                'marketing_features' => config('manual_billing.tier_marketing_bullets.'.$slug, []),
            ]);
        })->values();

        return Inertia::render('Billing/Gcash', [
            'tiers' => $tierRows,
            'gcashQrUrl' => config('manual_billing.gcash_qr_path'),
            'currencySymbol' => config('manual_billing.currency_symbol'),
            'currentDomain' => $domain->only(['id', 'name', 'name_slug']),
            'subscription' => $this->subscriptionService->subscriptionPropsForFrontend($domain),
            'showManualGcash' => (bool) config('manual_billing.show_manual_gcash_section'),
            'freeTier' => [
                'marketing_features' => config('manual_billing.free_tier_marketing_bullets', []),
                'product_limit' => config('features.unlimited_products', true)
                    ? null
                    : DomainSubscriptionService::FREE_TIER_MAX_PRODUCTS,
            ],
        ]);
    }

    public function store(Request $request, Domain $domain)
    {
        if (! config('manual_billing.show_manual_gcash_section')) {
            abort(403);
        }

        $ref = strtoupper(trim((string) $request->input('gcash_reference', '')));
        $request->merge(['gcash_reference' => $ref]);

        $validated = $request->validate([
            'service_tier_id' => [
                'required',
                Rule::exists('service_tiers', 'id')->where(function ($query): void {
                    $query->where('is_active', true);
                    ServiceTier::constrainVisibleOnBillingTierPicker($query);
                }),
            ],
            'gcash_reference' => [
                'required',
                'string',
                'max:64',
                Rule::unique('manual_payment_requests', 'gcash_reference')
                    ->where(fn ($q) => $q->where('status', ManualPaymentRequest::STATUS_PENDING)),
            ],
        ]);

        $tier = ServiceTier::query()->where('is_active', true)->findOrFail($validated['service_tier_id']);

        ManualPaymentRequest::create([
            'domain' => $domain->name_slug,
            'service_tier_id' => $tier->id,
            'amount' => $tier->amount,
            'gcash_reference' => $validated['gcash_reference'],
            'status' => ManualPaymentRequest::STATUS_PENDING,
            'submitted_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Payment reference submitted. Super admin will review shortly.');
    }
}
