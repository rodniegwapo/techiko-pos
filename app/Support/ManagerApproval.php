<?php

namespace App\Support;

use App\Models\UserPin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A manager or admin of the same organization approving something a cashier can't do alone (a
 * void, a points adjustment) by entering their PIN on the cashier's screen.
 */
class ManagerApproval
{
    /**
     * @return int The approving manager's user id.
     *
     * @throws ValidationException When no manager or admin of the organization has that PIN.
     */
    public static function approverFor(string $pinCode, ?string $domain, string $field = 'pin_code'): int
    {
        $managerPin = UserPin::whereHas('user', fn ($q) => $q->where('domain', $domain))
            ->whereHas('user.roles', fn ($q) => $q->whereIn('name', ['manager', 'admin']))
            ->get()
            ->first(fn ($pin) => Hash::check($pinCode, $pin->pin_code));

        if (! $managerPin) {
            throw ValidationException::withMessages([
                $field => ['The provided Pin Code is incorrect.'],
            ]);
        }

        return (int) $managerPin->user_id;
    }
}
