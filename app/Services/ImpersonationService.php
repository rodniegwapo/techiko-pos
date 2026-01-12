<?php

namespace App\Services;

use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ImpersonationService
{
    /**
     * Start impersonating a user.
     *
     * @throws \Exception
     */
    public function startImpersonation(User $userToImpersonate, User $impersonator): ImpersonationLog
    {
        // Validate that the impersonator is a super user
        if (! $impersonator->isSuperUser()) {
            throw new \Exception('Only super users can impersonate other users.');
        }

        // Prevent impersonating other super users
        if ($userToImpersonate->isSuperUser()) {
            throw new \Exception('You cannot impersonate other super users.');
        }

        // Prevent self-impersonation
        if ($userToImpersonate->id === $impersonator->id) {
            throw new \Exception('You cannot impersonate yourself.');
        }

        // Check if already impersonating
        if ($this->isImpersonating()) {
            throw new \Exception('You are already impersonating another user. Please stop the current impersonation first.');
        }

        // Store the original user ID in the session
        Session::put('impersonator_id', $impersonator->id);
        Session::put('impersonating', true);

        // Create impersonation log
        $log = ImpersonationLog::create([
            'impersonator_id' => $impersonator->id,
            'impersonated_user_id' => $userToImpersonate->id,
            'started_at' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        // Log in as the target user
        Auth::login($userToImpersonate);

        // Regenerate session to prevent CSRF issues and ensure fresh session
        request()->session()->regenerate();

        return $log;
    }

    /**
     * Stop impersonating and return to the original user.
     *
     * @return User|null The original user
     *
     * @throws \Exception
     */
    public function stopImpersonation(): ?User
    {
        if (! $this->isImpersonating()) {
            throw new \Exception('You are not currently impersonating anyone.');
        }

        $impersonatorId = Session::get('impersonator_id');
        $currentUserId = Auth::id();

        // Find and update the active impersonation log
        $log = ImpersonationLog::where('impersonator_id', $impersonatorId)
            ->where('impersonated_user_id', $currentUserId)
            ->whereNull('ended_at')
            ->latest()
            ->first();

        if ($log) {
            $log->update(['ended_at' => now()]);
        }

        // Get the original user
        $originalUser = User::find($impersonatorId);

        if (! $originalUser) {
            // Clear session and logout if original user not found
            Session::forget(['impersonator_id', 'impersonating']);
            Auth::logout();
            throw new \Exception('Original user not found.');
        }

        // Clear impersonation session data
        Session::forget(['impersonator_id', 'impersonating']);

        // Log back in as the original user
        Auth::login($originalUser);

        // Regenerate session to prevent session expiry and ensure fresh session
        request()->session()->regenerate();

        return $originalUser;
    }

    /**
     * Check if currently impersonating another user.
     */
    public function isImpersonating(): bool
    {
        return Session::has('impersonator_id') && Session::get('impersonating', false);
    }

    /**
     * Get the original user (impersonator) if currently impersonating.
     */
    public function getImpersonator(): ?User
    {
        if (! $this->isImpersonating()) {
            return null;
        }

        $impersonatorId = Session::get('impersonator_id');

        return User::find($impersonatorId);
    }

    /**
     * Get impersonation data for the current session.
     */
    public function getImpersonationData(): ?array
    {
        if (! $this->isImpersonating()) {
            return null;
        }

        $impersonator = $this->getImpersonator();
        $currentUser = Auth::user();

        if (! $impersonator || ! $currentUser) {
            return null;
        }

        return [
            'is_impersonating' => true,
            'impersonator' => [
                'id' => $impersonator->id,
                'name' => $impersonator->name,
                'email' => $impersonator->email,
            ],
            'impersonated_user' => [
                'id' => $currentUser->id,
                'name' => $currentUser->name,
                'email' => $currentUser->email,
            ],
        ];
    }

    /**
     * Get impersonation logs for a user.
     *
     * @param  bool  $asImpersonator  If true, get logs where user was the impersonator, otherwise where they were impersonated
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getLogsForUser(User $user, bool $asImpersonator = true)
    {
        if ($asImpersonator) {
            return ImpersonationLog::where('impersonator_id', $user->id)
                ->with(['impersonatedUser'])
                ->orderBy('started_at', 'desc')
                ->get();
        }

        return ImpersonationLog::where('impersonated_user_id', $user->id)
            ->with(['impersonator'])
            ->orderBy('started_at', 'desc')
            ->get();
    }
}
