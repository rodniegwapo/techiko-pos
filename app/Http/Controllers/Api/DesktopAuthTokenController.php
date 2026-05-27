<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Desktop\DesktopPostLoginValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Thin-client scaffold: Bearer token authentication for API consumers only.
 *
 * Sessions are unaffected; bundled NativePHP/Inertia desktop continues to use /desktop/login + cookies.
 */
class DesktopAuthTokenController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited($request);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $this->hitRateLimiter($request);
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        DesktopPostLoginValidator::validate($user);

        RateLimiter::clear($this->throttleKey($request));

        return response()->json([
            'token' => $user->createToken('desktop')->plainTextToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'domain' => $user->domain,
            ],
        ]);
    }

    protected function throttleKey(Request $request): string
    {
        return Str::transliterate(
            'desktop-api|'.Str::lower((string) $request->input('email')).'|'.$request->ip()
        );
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function hitRateLimiter(Request $request): void
    {
        RateLimiter::hit($this->throttleKey($request));
    }
}
