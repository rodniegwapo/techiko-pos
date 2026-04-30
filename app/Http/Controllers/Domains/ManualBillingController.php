<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ManualPaymentRequest;
use App\Models\PaymongoCheckout;
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
        $tiers = ServiceTier::query()->active()->get();

        $paymongoQr = session('paymongo_qr');
        if (is_array($paymongoQr) && ! empty($paymongoQr['payment_intent_id'])) {
            $paid = PaymongoCheckout::query()
                ->where('payment_intent_id', $paymongoQr['payment_intent_id'])
                ->where('status', PaymongoCheckout::STATUS_PAID)
                ->exists();
            if ($paid) {
                session()->forget('paymongo_qr');
                $paymongoQr = null;
            }
        }

        $basicSlug = strtolower((string) config('manual_billing.servicing_basic_tier_slug'));

        $tierRows = $tiers->map(function (ServiceTier $tier) use ($basicSlug): array {
            return array_merge($tier->toArray(), [
                'uses_vite_bundle_qrph' => $basicSlug !== '' && strtolower((string) $tier->slug) === $basicSlug,
            ]);
        })->values();

        return Inertia::render('Billing/Gcash', [
            'tiers' => $tierRows,
            'gcashQrUrl' => config('manual_billing.gcash_qr_path'),
            'currencySymbol' => config('manual_billing.currency_symbol'),
            'currentDomain' => $domain->only(['id', 'name', 'name_slug']),
            'subscription' => $this->subscriptionService->subscriptionPropsForFrontend($domain),
            'paymongoQr' => $paymongoQr,
            'paymongoConfigured' => filled(config('paymongo.secret_key')),
            'showManualGcash' => (bool) config('manual_billing.show_manual_gcash_section'),
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
            'service_tier_id' => ['required', Rule::exists('service_tiers', 'id')->where('is_active', true)],
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
