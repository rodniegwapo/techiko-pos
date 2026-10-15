<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\PaymongoCheckout;
use App\Models\ServiceTier;
use App\Models\User;
use App\Services\PayMongo\PayMongoCheckoutFulfillmentService;
use App\Services\PayMongo\PayMongoQrPhCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Pay now" on the subscription on hold page: a PayMongo QR Ph code for the chosen plan and months.
 * Once PayMongo says it's paid, the organization unlocks (a super admin confirms the money later).
 * Open while locked, so only the organization's admins and managers (who can see Servicing payment) may use it.
 */
class SubscriptionRenewalController extends Controller
{
    /** Who in an organization may pay its subscription. */
    public static function canPay(User $user, Domain $domain): bool
    {
        return $user->isSuperUser()
            || ($user->domain === $domain->name_slug && $user->hasPermissionToRoute('billing.servicing.index'));
    }

    /** Online payment needs PayMongo's secret key (its client can't be made without one). */
    public static function paymentsReady(): bool
    {
        return (string) config('paymongo.secret_key') !== '';
    }

    public function start(Request $request, Domain $domain): JsonResponse
    {
        abort_unless(self::canPay($request->user(), $domain), 403);
        if (! self::paymentsReady()) {
            return response()->json(['message' => "Online payment isn't set up yet. Please contact us to renew."], 422);
        }
        $checkouts = app(PayMongoQrPhCheckoutService::class);

        $validated = $request->validate([
            'service_tier_id' => ['required', 'integer', Rule::exists('service_tiers', 'id')->where(function ($query): void {
                $query->where('is_active', true);
                ServiceTier::constrainVisibleOnBillingTierPicker($query);
            })],
            'months' => ['required', 'integer', Rule::in([1, 3, 6, 12])],
        ]);

        $tier = ServiceTier::query()->findOrFail($validated['service_tier_id']);
        $result = $checkouts->startCheckout($domain, $tier, $request->user(), (int) $validated['months']);
        $checkout = $result['checkout'];

        return response()->json([
            'payment_intent_id' => $checkout->payment_intent_id,
            'qr_image_data_url' => $result['qr_image_data_url'],
            'amount' => $checkout->amount_centavos / 100,
            'months' => $checkout->months,
            'tier_name' => $tier->name,
            'expires_at' => $checkout->expires_at?->toIso8601String(),
        ]);
    }

    public function status(Request $request, Domain $domain): JsonResponse
    {
        abort_unless(self::canPay($request->user(), $domain), 403);
        $intentId = $request->validate(['payment_intent_id' => ['required', 'string']])['payment_intent_id'];

        $checkout = PaymongoCheckout::query()
            ->where('payment_intent_id', $intentId)
            ->where('domain', $domain->name_slug)
            ->firstOrFail();

        // The webhook may already have marked it paid; otherwise ask PayMongo.
        if ($checkout->status === PaymongoCheckout::STATUS_PENDING && self::paymentsReady()) {
            app(PayMongoCheckoutFulfillmentService::class)->syncPaymentIntent($intentId);
        }
        $paid = $checkout->fresh()->status === PaymongoCheckout::STATUS_PAID;

        return response()->json([
            'paid' => $paid,
            'next_due_on' => $paid ? $domain->fresh()->subscription_ends_at?->toDateString() : null,
        ]);
    }
}
