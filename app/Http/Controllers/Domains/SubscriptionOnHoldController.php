<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\ServiceTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** What a locked organization's users see instead of its pages (see EnsureDomainNotLocked). */
class SubscriptionOnHoldController extends Controller
{
    public function __invoke(Request $request, Domain $domain): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperUser() || $user->domain === $domain->name_slug, 403);

        // Unlocked again: back to the organization.
        if (! $domain->isLocked()) {
            return redirect()->route('domains.dashboard', ['domain' => $domain->name_slug]);
        }

        return Inertia::render('SubscriptionOnHold', [
            'organization' => $domain->name,
            'endedOn' => $domain->subscription_ends_at?->toDateString(),
            'contactUrl' => route('marketing.contact'),
            // "Pay now" (PayMongo QR Ph) for the people who handle the plan; others ask their admin.
            'canPay' => SubscriptionRenewalController::canPay($user, $domain),
            'paymentsReady' => SubscriptionRenewalController::paymentsReady(),
            'tiers' => ServiceTier::query()->active()
                ->where(fn ($query) => ServiceTier::constrainVisibleOnBillingTierPicker($query))
                ->get(['id', 'name', 'amount'])
                ->map(fn (ServiceTier $tier) => ['id' => $tier->id, 'name' => $tier->name, 'amount' => (float) $tier->amount])
                ->values(),
            'currentTierId' => $domain->current_service_tier_id,
            'currencySymbol' => config('manual_billing.currency_symbol', '₱'),
        ]);
    }
}
