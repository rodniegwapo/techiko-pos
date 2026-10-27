<?php

namespace App\Support\Wallet;

use App\Models\RegisterShift;
use App\Models\User;
use App\Models\WalletCashReconciliation;
use Illuminate\Validation\ValidationException;

class WalletShiftGuard
{
    public static function isClosed(string $domainSlug, int $locationId, string $dateYmd): bool
    {
        return WalletCashReconciliation::query()
            ->forWalletContext($domainSlug, $locationId)
            ->whereDate('business_date', $dateYmd)
            ->where('is_closed', true)
            ->exists();
    }

    public static function ensureDateNotClosed(string $domainSlug, int $locationId, string $dateYmd, string $field = 'business_date'): void
    {
        if (self::isClosed($domainSlug, $locationId, $dateYmd)) {
            throw ValidationException::withMessages([
                $field => 'Shift is closed for this date/location. Reopen to edit.',
            ]);
        }
    }

    /** Anyone whose role holds the reopen permission may reopen a closed shift (the reopen is logged with a reason). */
    public static function canReopen(User $user): bool
    {
        return $user->hasPermissionToRoute('wallet-cash-ledger.reopen-shift');
    }

    /**
     * Taking payment today needs the cashier's own shift open at the store, and the store's day not
     * ended. Another cashier's shift (open, ended or waiting for review) makes no difference.
     *
     * @return RegisterShift The shift the payment belongs to.
     */
    public static function ensureShiftOpenForSale(string $domainSlug, int $locationId, string $dateYmd, User $user, string $field = 'shift'): RegisterShift
    {
        if (self::isClosed($domainSlug, $locationId, $dateYmd)) {
            throw ValidationException::withMessages([
                $field => 'The store\'s day was ended. A manager reopens it before taking payment.',
            ]);
        }

        return RegisterShift::openFor($domainSlug, $locationId, (int) $user->id)
            ?? throw ValidationException::withMessages([
                $field => 'You have no shift open at this store. Start your shift before taking payment.',
            ]);
    }
}
