<?php

namespace App\Services\Finance;

use App\Models\WalletCashMovement;
use App\Support\Wallet\WalletShiftGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Keeps a supplier payment or other income in step with the store's wallet ledger, the same way
 * ExpenseService does for expenses: paid from (or into) the cash register means a ledger line,
 * linked through the record's wallet_cash_movement_id, and a closed shift can't be changed.
 */
class CashRegisterPosting
{
    /**
     * Create, move or remove the record's ledger line to match it as it is now. Call before
     * saving the record (it sets wallet_cash_movement_id).
     */
    public function sync(Model $record, string $kind, string $direction, string $date, string $notes, string $dateField): void
    {
        $movement = $record->wallet_cash_movement_id
            ? WalletCashMovement::query()->find($record->wallet_cash_movement_id)
            : null;

        if ($record->payment_method !== 'cash_register') {
            $movement?->delete();
            $record->wallet_cash_movement_id = null;

            return;
        }

        WalletShiftGuard::ensureDateNotClosed($record->domain, (int) $record->location_id, $date, $dateField);

        $attributes = [
            'domain' => $record->domain,
            'location_id' => $record->location_id,
            'payment_card_type_id' => null,
            'direction' => $direction,
            'amount' => $record->amount,
            'kind' => $kind,
            'notes' => Str::limit($notes, 250),
            'movement_date' => $date,
            'user_id' => $record->user_id,
        ];

        if ($movement) {
            $movement->update($attributes);
        } else {
            $record->wallet_cash_movement_id = WalletCashMovement::query()->create($attributes)->id;
        }
    }

    /** Refuses to touch a record whose existing ledger line sits on a closed shift. */
    public function guardExisting(Model $record, string $dateField): void
    {
        $original = $record->getOriginal();
        if (! empty($original['wallet_cash_movement_id']) && ! empty($original['location_id'])) {
            WalletShiftGuard::ensureDateNotClosed(
                $record->domain,
                (int) $original['location_id'],
                $record->getOriginal($dateField)->toDateString(),
                $dateField,
            );
        }
    }

    /** Deletes the record and its ledger line together. */
    public function delete(Model $record, string $dateField): void
    {
        $this->guardExisting($record, $dateField);

        $movementId = $record->wallet_cash_movement_id;
        $record->delete();

        if ($movementId) {
            WalletCashMovement::query()->whereKey($movementId)->delete();
        }
    }
}
