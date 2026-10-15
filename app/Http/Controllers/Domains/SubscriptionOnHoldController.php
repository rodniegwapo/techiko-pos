<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
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
        ]);
    }
}
