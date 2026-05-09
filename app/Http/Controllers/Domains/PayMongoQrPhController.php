<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\PaymongoCheckout;
use App\Models\ServiceTier;
use App\Models\User;
use App\Services\DomainSubscriptionService;
use App\Services\PayMongo\PayMongoCheckoutFulfillmentService;
use App\Services\PayMongo\PayMongoQrPhCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayMongoQrPhController extends Controller
{
    public function __construct(
        private DomainSubscriptionService $subscriptionService,
    ) {}

    public function store(
        Request $request,
        Domain $domain,
        PayMongoQrPhCheckoutService $checkoutService,
    ) {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'service_tier_id' => [
                'required',
                Rule::exists('service_tiers', 'id')->where(function ($query): void {
                    $query->where('is_active', true);
                    ServiceTier::constrainVisibleOnBillingTierPicker($query);
                }),
            ],
        ]);

        $tier = ServiceTier::query()
            ->where('is_active', true)
            ->findOrFail($validated['service_tier_id']);

        $basicSlug = strtolower((string) config('manual_billing.servicing_basic_tier_slug'));
        if ($basicSlug !== '' && strtolower((string) $tier->slug) === $basicSlug) {
            throw ValidationException::withMessages([
                'service_tier_id' => [__('This plan uses the on-screen QR Ph code; payment intent checkout is not available for it.')],
            ]);
        }

        $result = $checkoutService->startCheckout($domain, $tier, $user);

        Session::put('paymongo_qr', [
            'payment_intent_id' => $result['checkout']->payment_intent_id,
            'qr_image_data_url' => $result['qr_image_data_url'],
            'payment_intent_status' => $result['payment_intent_status'],
            'expires_at' => $result['checkout']->expires_at?->toIso8601String(),
            'tier_name' => $tier->name,
        ]);

        return redirect()->back()->with('success', 'Scan the QR Ph code with your bank or e-wallet app to pay.');
    }

    public function status(
        Request $request,
        Domain $domain,
        PayMongoCheckoutFulfillmentService $fulfillment,
    ): JsonResponse {
        $validated = $request->validate([
            'payment_intent_id' => ['required', 'string'],
        ]);

        $checkout = PaymongoCheckout::query()
            ->where('payment_intent_id', $validated['payment_intent_id'])
            ->where('domain', $domain->name_slug)
            ->first();

        if ($checkout === null) {
            abort(404, 'Checkout not found.');
        }

        $paid = $fulfillment->syncPaymentIntent($validated['payment_intent_id']);
        $isPaid = $paid || $checkout->fresh()?->status === PaymongoCheckout::STATUS_PAID;

        if ($isPaid) {
            Session::forget('paymongo_qr');
        }

        return response()->json([
            'paid' => $isPaid,
            'checkout_status' => $checkout->fresh()?->status,
            'subscription' => $this->subscriptionService->subscriptionPropsForFrontend(
                $domain->loadMissing('currentServiceTier'),
            ),
        ]);
    }
}
