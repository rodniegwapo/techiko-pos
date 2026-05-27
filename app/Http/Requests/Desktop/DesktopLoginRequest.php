<?php

namespace App\Http\Requests\Desktop;

use App\Support\Desktop\DesktopPostLoginValidator;
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

        DesktopPostLoginValidator::validate(Auth::user());

        RateLimiter::clear($this->throttleKey());
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
    protected function throwValidationError(string $message): void
    {
        throw ValidationException::withMessages(['email' => $message]);
    }
}
