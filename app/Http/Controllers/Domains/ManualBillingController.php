<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ManualPaymentRequest;
use App\Models\ServiceTier;
use App\Services\Ai\AiCreditService;
use App\Services\DomainSubscriptionService;
use App\Services\SubscriptionPaymentService;
use App\Support\FreePlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ManualBillingController extends Controller
{
    public function __construct(
        private DomainSubscriptionService $subscriptionService,
        private AiCreditService $aiCredits,
        private SubscriptionPaymentService $payments,
    ) {}

    public function index(Request $request, Domain $domain)
    {
        $domain->load('currentServiceTier');
        $tiers = ServiceTier::query()
            ->active()
            ->get()
            ->reject(fn (ServiceTier $tier) => ServiceTier::isHiddenFromBilling($tier))
            ->values();

        $planGating = (bool) config('features.plan_gating', false);
        $current = $domain->current_service_tier_id ? $domain->currentServiceTier : null;

        $tierRows = $tiers->map(function (ServiceTier $tier) use ($current): array {
            $slug = strtolower((string) $tier->slug);

            return array_merge($tier->toArray(), [
                'marketing_features' => config('manual_billing.tier_marketing_bullets.'.$slug, []),
                // What switching to it means from the current plan (any paid plan is an upgrade from Free).
                'relation' => match (true) {
                    $current?->id === $tier->id => 'current',
                    $current === null => 'upgrade',
                    (float) $tier->amount > (float) $current->amount => 'upgrade',
                    default => 'downgrade',
                },
            ]);
        })->values();

        return Inertia::render('Billing/Gcash', [
            // VIP: everything included, nothing to pay (the page shows a VIP card instead of plans).
            'isVip' => $domain->isVip(),
            'vipSince' => $domain->vip_at?->toDateString(),
            'tiers' => $tierRows,
            'gcashQrUrl' => config('manual_billing.gcash_qr_path'),
            'currencySymbol' => config('manual_billing.currency_symbol'),
            'currentDomain' => $domain->only(['id', 'name', 'name_slug']),
            'subscription' => $this->subscriptionService->subscriptionPropsForFrontend($domain),
            // Read-only: when the next payment is due and what was paid (recorded by Techiko).
            'nextPayment' => $this->payments->nextPayment($domain),
            'payments' => collect($this->payments->history($domain, 12, withVoided: false))
                ->map(fn (array $row) => collect($row)->except(['note', 'recorded_by', 'voided'])->all())
                ->all(),
            'aiCredits' => $this->aiCredits->propsForFrontend($domain),
            'aiUsageUrl' => $request->user()?->hasPermissionToRoute('ai-usage.index')
                ? route('domains.ai-usage.index', ['domain' => $domain->name_slug])
                : null,
            'showManualGcash' => (bool) config('manual_billing.show_manual_gcash_section'),
            // Upgrade and Renew pay with a PayMongo QR Ph code (domains.renew.start), when it's set up.
            'canPay' => SubscriptionRenewalController::canPay($request->user(), $domain),
            'paymentsReady' => SubscriptionRenewalController::paymentsReady(),
            'currentTier' => $current ? [
                'id' => $current->id,
                'name' => $current->name,
                'slug' => $current->slug,
                'amount' => (float) $current->amount,
            ] : null,
            'freeTier' => [
                'marketing_features' => config('manual_billing.free_tier_marketing_bullets', []),
                'product_limit' => config('features.unlimited_products', true)
                    ? null
                    : FreePlan::maxProducts(),
                // The free plan's other limits, only while plans are enforced (config features.plan_gating).
                'user_limit' => $planGating ? FreePlan::maxUsers() : null,
                'location_limit' => $planGating ? 1 : null,
                'history_days' => $planGating ? FreePlan::historyDays() : null,
            ],
        ]);
    }

    public function store(Request $request, Domain $domain)
    {
        if (! config('manual_billing.show_manual_gcash_section') || $domain->isVip()) {
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
