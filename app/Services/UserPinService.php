<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\User;
use App\Models\UserPin;
use Illuminate\Support\Facades\Hash;

class UserPinService
{
    /**
     * Whether another user in the same domain already has this plaintext PIN (active pins only).
     */
    public function pinTakenInDomain(Domain $domain, User $exceptUser, string $plain): bool
    {
        $pins = UserPin::query()
            ->where('active', true)
            ->where('user_id', '!=', $exceptUser->id)
            ->whereHas('user', fn ($q) => $q->where('domain', $domain->name_slug))
            ->get(['pin_code']);

        foreach ($pins as $pin) {
            if (Hash::check($plain, $pin->pin_code)) {
                return true;
            }
        }

        return false;
    }
}
