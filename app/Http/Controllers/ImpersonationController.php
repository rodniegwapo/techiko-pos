<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function __construct(
        private ImpersonationService $impersonationService
    ) {
    }

    /**
     * Start impersonating a user.
     *
     * @param User $user The user to impersonate
     * @return RedirectResponse
     */
    public function impersonate(User $user): RedirectResponse
    {
        $impersonator = Auth::user();

        try {
            // Validate that the impersonator is a super user
            if (!$impersonator->isSuperUser()) {
                return redirect()->back()->with('error', 'Only super users can impersonate other users.');
            }

            // Start the impersonation
            $this->impersonationService->startImpersonation($user, $impersonator);

            // Smart redirect based on impersonated user's domain
            // This matches the logic in AuthenticatedSessionController
            if ($user->domain) {
                // User has a domain - redirect to domain-specific dashboard
                return redirect()->route('domains.sales.index', ['domain' => $user->domain])
                    ->with('success', "You are now impersonating {$user->name}");
            }

            // User has no domain - redirect to global dashboard
            return redirect()->route('dashboard')
                ->with('success', "You are now impersonating {$user->name}");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Stop impersonating and return to the original user.
     *
     * @return RedirectResponse
     */
    public function stopImpersonating(): RedirectResponse
    {
        try {
            $originalUser = $this->impersonationService->stopImpersonation();

            // Always redirect super admin back to global context
            // Use dashboard instead of users.index for better UX
            return redirect()->route('dashboard')
                ->with('success', "You are now back as {$originalUser->name}");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
