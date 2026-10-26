<?php

namespace App\Support\Wallet;

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

    /** Unlike isClosed(), "no reconciliation row yet" does NOT count as open here. */
    public static function isOpenForSale(string $domainSlug, int $locationId, string $dateYmd): bool
    {
        return WalletCashReconciliation::query()
            ->forWalletContext($domainSlug, $locationId)
            ->whereDate('business_date', $dateYmd)
            ->where('is_closed', false)
            ->exists();
    }

    public static function ensureShiftOpenForSale(string $domainSlug, int $locationId, string $dateYmd, string $field = 'shift'): void
    {
        if (! self::isOpenForSale($domainSlug, $locationId, $dateYmd)) {
            throw ValidationException::withMessages([
                $field => 'No shift is open for this location today. Start the shift before taking payment.',
            ]);
        }
    }
}
