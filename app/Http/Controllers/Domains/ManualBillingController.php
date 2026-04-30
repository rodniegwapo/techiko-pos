<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ManualPaymentRequest;
use App\Models\ServiceTier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ManualBillingController extends Controller
{
    public function index(Request $request, Domain $domain)
    {
        $tiers = ServiceTier::query()->active()->get();

        return Inertia::render('Billing/Gcash', [
            'tiers' => $tiers,
            'gcashQrUrl' => config('manual_billing.gcash_qr_path'),
            'currencySymbol' => config('manual_billing.currency_symbol'),
            'currentDomain' => $domain->only(['id', 'name', 'name_slug']),
        ]);
    }

    public function store(Request $request, Domain $domain)
    {
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
