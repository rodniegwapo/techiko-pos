<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Super admins record an organization's subscription payments (e.g. a bank transfer) and void a mistaken one. */
class DomainPaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionPaymentService $payments,
    ) {}

    public function store(Request $request, Domain $domain): RedirectResponse
    {
        $validated = $request->validate([
            'service_tier_id' => ['required', 'integer', Rule::exists('service_tiers', 'id')->where('is_active', true)],
            'months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference_number' => ['required', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'unlock' => ['nullable', 'boolean'],
        ]);

        $payment = $this->payments->record($domain, [...$validated, 'method' => 'bank_transfer'], $request->user());

        return back()->with('success', 'Payment recorded. Next payment is due '.$payment->period_end->format('M j, Y').'.');
    }

    public function void(Request $request, Domain $domain, SubscriptionPayment $payment): RedirectResponse
    {
        abort_unless($payment->domain === $domain->name_slug, 404);

        $this->payments->void($payment, $request->user());

        return back()->with('success', 'Payment voided. The next payment date went back.');
    }
}
