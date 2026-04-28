<?php

namespace App\Http\Requests\Desktop;

use App\Models\Domain;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DesktopLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->hitRateLimiter();
            $this->throwValidationError(trans('auth.failed'));
        }

        $user = Auth::user();

        if ($user && $user->isSuperUser() && config('nativephp-internal.running')) {
            $this->logoutAndFail('The desktop app is for organization accounts. Use the web app for administrator access.');
        }

        if ($user && ! $user->isSuperUser()) {
            $this->validateDomainAndUser($user);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    protected function validateDomainAndUser($user): void
    {
        if (! $user->domain) {
            $this->logoutAndFail('Your organization is not registered yet. Please contact support.');
        }

        $domain = Domain::where('name_slug', $user->domain)->first();

        if (! $domain || ! $domain->is_active) {
            $this->logoutAndFail('Your organization is pending approval or inactive. Please wait for admin activation.');
        }

        if ($user->status !== 'active') {
            $this->logoutAndFail('Your account has been deactivated. Please contact an administrator.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        $this->throwValidationError(trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => ceil($seconds / 60),
        ]));
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(
            'desktop|'.Str::lower($this->string('email')).'|'.$this->ip()
        );
    }

    protected function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    protected function logoutAndFail(string $message): void
    {
        Auth::logout();
        $this->hitRateLimiter();
        $this->throwValidationError($message);
    }

    /**
     * @throws ValidationException
     */
    protected function throwValidationError(string $message): void
    {
        throw ValidationException::withMessages(['email' => $message]);
    }
}
