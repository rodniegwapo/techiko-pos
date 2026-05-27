<?php

namespace App\Support\Desktop;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Validates a user after successful password verification / login attempt for desktop entry.
 *
 * Mirrors rules previously embedded in DesktopLoginRequest::authenticate().
 */
class DesktopPostLoginValidator
{
    /**
     * @throws ValidationException
     */
    public static function validate(User $user): void
    {
        if ($user->isSuperUser()) {
            if (config('nativephp-internal.running')) {
                Auth::logout();
                self::fail('The desktop app is for organization accounts. Use the web app for administrator access.');
            }

            return;
        }

        if (! $user->domain) {
            Auth::logout();
            self::fail('Your organization is not registered yet. Please contact support.');
        }

        $domain = Domain::where('name_slug', $user->domain)->first();

        if (! $domain || ! $domain->is_active) {
            Auth::logout();
            self::fail('Your organization is pending approval or inactive. Please wait for admin activation.');
        }

        if ($user->status !== 'active') {
            Auth::logout();
            self::fail('Your account has been deactivated. Please contact an administrator.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected static function fail(string $message): void
    {
        throw ValidationException::withMessages(['email' => $message]);
    }
}
