<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PublicVerifyEmailController extends Controller
{
    /**
     * Verify email from signed link without requiring prior login (fixes auth/verify deadlock).
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = User::find($request->route('id'));

        if ($user === null) {
            abort(404);
        }

        if (! hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        Auth::login($user, remember: false);
        $request->session()->regenerate();

        // Match AuthenticatedSessionController redirect intent after login
        $user = Auth::user();
        if ($user->isSuperUser()) {
            return redirect()->intended(AppServiceProvider::HOME.'?verified=1');
        }

        if ($user->domain) {
            return redirect()->intended(
                route('domains.sales.index', ['domain' => $user->domain]).'?verified=1'
            );
        }

        return redirect()->intended(AppServiceProvider::HOME.'?verified=1');
    }
}
