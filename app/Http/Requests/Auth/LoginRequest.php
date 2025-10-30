<?php

namespace App\Http\Requests\Auth;

use App\Models\Domain;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (!Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->hitRateLimiter();
            $this->throwValidationError(trans('auth.failed'));
        }

        $user = Auth::user();

        if ($user && !$user->isSuperUser()) {
            $this->validateDomainAndUser($user);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Validate the user's domain and account status.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validateDomainAndUser($user): void
    {
        if (!$user->domain) {
            $this->logoutAndFail('Your organization is not registered yet. Please contact support.');
        }

        $domain = Domain::where('name_slug', $user->domain)->first();

        if (!$domain || !$domain->is_active) {
            $this->logoutAndFail('Your organization is pending approval or inactive. Please wait for admin activation.');
        }

        if ($user->status !== 'active') {
            $this->logoutAndFail('Your account has been deactivated. Please contact an administrator.');
        }
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (!RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        $this->throwValidationError(trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => ceil($seconds / 60),
        ]));
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('email')) . '|' . $this->ip()
        );
    }

    /**
     * Increment the rate limiter hit count.
     */
    protected function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    /**
     * Log out user and throw a validation error with message.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function logoutAndFail(string $message): void
    {
        Auth::logout();
        $this->hitRateLimiter();
        $this->throwValidationError($message);
    }

    /**
     * Throw a standardized validation exception for email errors.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function throwValidationError(string $message): void
    {
        throw ValidationException::withMessages(['email' => $message]);
    }
}
