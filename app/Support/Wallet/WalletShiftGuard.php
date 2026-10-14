<?php

namespace App\Support\Wallet;

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
}
