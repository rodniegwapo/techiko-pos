<?php

namespace App\Services\Wallet;

use App\Models\Sale;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Support\Wallet\WalletShiftGuard;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Completing a sale paid in cash or e-wallet posts an "in" movement to that store's wallet ledger,
 * mirroring how expenses and supplier payments already post their cash-register "out" movements.
 * Card, bank and credit sales never touch the wallet.
 */
class SalePaymentWalletPosting
{
    private const POSTABLE_METHODS = ['cash', 'e-wallet'];

    /**
     * Call once, inside the same transaction that marks the sale paid, after payment_method,
     * location_id and (for split sales) the sale_payments rows are final.
     */
    public function postForCompletedSale(Sale $sale): void
    {
        $date = Carbon::parse($sale->transaction_date)->toDateString();
        WalletShiftGuard::ensureDateNotClosed($sale->domain, (int) $sale->location_id, $date);

        if ($sale->payment_method === 'split') {
            foreach ($sale->payments()->whereIn('method', self::POSTABLE_METHODS)->get() as $part) {
                $movement = WalletCashMovement::query()->create([
                    'domain' => $sale->domain,
                    'location_id' => $sale->location_id,
                    'payment_card_type_id' => $part->payment_card_type_id,
                    'direction' => 'in',
                    'amount' => round((float) $part->amount, 2),
                    'kind' => WalletCashMovement::KIND_SALE_PAYMENT,
                    'notes' => Str::limit("Sale #{$sale->invoice_number} ({$part->method})", 250),
                    'movement_date' => $date,
                    'user_id' => $sale->user_id,
                ]);
                $part->update(['wallet_cash_movement_id' => $movement->id]);
            }

            return;
        }

        if (! in_array($sale->payment_method, self::POSTABLE_METHODS, true)) {
            return;
        }

        $movement = WalletCashMovement::query()->create([
            'domain' => $sale->domain,
            'location_id' => $sale->location_id,
            'payment_card_type_id' => $sale->payment_card_type_id,
            'direction' => 'in',
            'amount' => round((float) $sale->grand_total, 2),
            'kind' => WalletCashMovement::KIND_SALE_PAYMENT,
            'notes' => Str::limit("Sale #{$sale->invoice_number}", 250),
            'movement_date' => $date,
            'user_id' => $sale->user_id,
        ]);
        $sale->update(['wallet_cash_movement_id' => $movement->id]);
    }

    /**
     * Reverse the movement(s) tied to a voided sale with a fresh "out" entry dated today, rather
     * than editing the original (which may sit on an already-closed, already-counted shift).
     */
    public function reverseForVoidedSale(Sale $sale, User $voidedBy): void
    {
        $today = now()->toDateString();
        WalletShiftGuard::ensureDateNotClosed($sale->domain, (int) $sale->location_id, $today);

        $movementIds = collect([$sale->wallet_cash_movement_id])
            ->merge($sale->payments()->whereNotNull('wallet_cash_movement_id')->pluck('wallet_cash_movement_id'))
            ->filter()
            ->unique();

        if ($movementIds->isEmpty()) {
            return;
        }

        foreach (WalletCashMovement::query()->whereKey($movementIds)->get() as $original) {
            WalletCashMovement::query()->create([
                'domain' => $sale->domain,
                'location_id' => $sale->location_id,
                'payment_card_type_id' => $original->payment_card_type_id,
                'direction' => 'out',
                'amount' => $original->amount,
                'kind' => WalletCashMovement::KIND_SALE_VOID,
                'notes' => Str::limit("Void reversal: Sale #{$sale->invoice_number}", 250),
                'movement_date' => $today,
                'user_id' => $voidedBy->id,
            ]);
        }
    }
}
