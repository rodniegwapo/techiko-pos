<?php

namespace App\Services\Finance;

use App\Models\Finance\FinancialAccount;
use App\Models\PaymentCardType;
use App\Models\WalletCashMovement;
use App\Support\PaidFromAccount;
use App\Support\Wallet\WalletShiftGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Keeps a finance record (expense, supplier payment, other income, loan, asset purchase, owner
 * investment) in step with the wallet ledger, linked through the record's wallet_cash_movement_id:
 * - paid from (or into) the cash register: a line in that store's drawer;
 * - paid by bank or e-wallet from an account linked to a payment channel: a line on that channel,
 *   at the channel's store, so the channel's balance follows;
 * - anything else: no line.
 * A line on a closed day can't be added, changed or removed.
 */
class CashRegisterPosting
{
    /**
     * Create, move or remove the record's ledger line to match it as it is now. Call before
     * saving the record (it sets wallet_cash_movement_id).
     */
    public function sync(Model $record, string $kind, string $direction, string $date, string $notes, string $dateField, ?float $amount = null): void
    {
        $amount ??= (float) $record->amount;

        $movement = $record->wallet_cash_movement_id
            ? WalletCashMovement::query()->find($record->wallet_cash_movement_id)
            : null;

        $target = $this->target($record);
        if ($target === null) {
            $movement?->delete();
            $record->wallet_cash_movement_id = null;

            return;
        }
        [$locationId, $channelId] = $target;

        WalletShiftGuard::ensureDateNotClosed($record->domain, $locationId, $date, $dateField);

        $attributes = [
            'domain' => $record->domain,
            'location_id' => $locationId,
            'payment_card_type_id' => $channelId,
            'direction' => $direction,
            'amount' => round($amount, 2),
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

    /** Refuses to touch a record whose existing ledger line sits on a closed day. */
    public function guardExisting(Model $record, string $dateField): void
    {
        $movementId = $record->getOriginal('wallet_cash_movement_id');
        $movement = $movementId ? WalletCashMovement::query()->find($movementId) : null;
        if ($movement) {
            WalletShiftGuard::ensureDateNotClosed(
                $record->domain,
                (int) $movement->location_id,
                $movement->movement_date->toDateString(),
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

    /**
     * Where the money moved: [store, channel or null for the drawer], or null when it moved
     * outside the wallet (or through an account not linked to a channel).
     *
     * @return array{0: int, 1: ?int}|null
     */
    private function target(Model $record): ?array
    {
        if ($record->payment_method === 'cash_register') {
            return [(int) $record->location_id, null];
        }

        $accountId = $record->getAttribute('financial_account_id');
        if (! $accountId || ! in_array($record->payment_method, PaidFromAccount::METHODS, true)) {
            return null;
        }

        $channelId = FinancialAccount::query()
            ->where('domain', $record->domain)
            ->whereKey($accountId)
            ->value('payment_card_type_id');
        $channel = $channelId
            ? PaymentCardType::query()->forDomain($record->domain)->find($channelId, ['id', 'location_id'])
            : null;

        return $channel ? [(int) $channel->location_id, (int) $channel->id] : null;
    }
}
