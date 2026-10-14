<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\WalletCashMovement;
use App\Support\ExpenseReceiptStorage;
use App\Support\Wallet\WalletShiftGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saving an expense paid from the cash register also posts a cash-out to that store's wallet
 * ledger, so the drawer's expected cash stays right. The two are kept in step on edit and delete.
 */
class ExpenseService
{
    /**
     * @param  array{domain: string, location_id: ?int, expense_category_id: int, amount: float|string,
     *     expense_date: string, description: string, payee?: ?string, payment_method: string,
     *     reference_no?: ?string, notes?: ?string, user_id: int}  $data
     */
    public function create(array $data, ?UploadedFile $receipt = null): Expense
    {
        return DB::transaction(function () use ($data, $receipt) {
            $expense = new Expense($data);
            $this->syncWalletMovement($expense);

            if ($receipt) {
                $expense->receipt_path = ExpenseReceiptStorage::put($receipt, $expense->domain);
            }

            $expense->save();

            return $expense;
        });
    }

    public function update(Expense $expense, array $data, ?UploadedFile $receipt = null, bool $removeReceipt = false): Expense
    {
        return DB::transaction(function () use ($expense, $data, $receipt, $removeReceipt) {
            // The old cash-out may sit on a now-closed shift; changing it would rewrite a counted drawer.
            $this->guardExistingMovement($expense);

            $expense->fill($data);
            $this->syncWalletMovement($expense);

            $oldReceipt = $expense->receipt_path;
            if ($receipt) {
                $expense->receipt_path = ExpenseReceiptStorage::put($receipt, $expense->domain);
            } elseif ($removeReceipt) {
                $expense->receipt_path = null;
            }

            $expense->save();

            if ($oldReceipt && $oldReceipt !== $expense->receipt_path) {
                DB::afterCommit(fn () => ExpenseReceiptStorage::delete($oldReceipt));
            }

            return $expense;
        });
    }

    public function delete(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            $this->guardExistingMovement($expense);

            $movementId = $expense->wallet_cash_movement_id;
            $expense->wallet_cash_movement_id = null;
            $expense->save();
            $expense->delete();

            if ($movementId) {
                WalletCashMovement::query()->whereKey($movementId)->delete();
            }
        });
    }

    /** Create, move or remove the linked ledger cash-out to match the expense as it is now. */
    private function syncWalletMovement(Expense $expense): void
    {
        $movement = $expense->wallet_cash_movement_id
            ? WalletCashMovement::query()->find($expense->wallet_cash_movement_id)
            : null;

        if ($expense->payment_method !== 'cash_register') {
            if ($movement) {
                $movement->delete();
            }
            $expense->wallet_cash_movement_id = null;

            return;
        }

        $date = $expense->expense_date->toDateString();
        WalletShiftGuard::ensureDateNotClosed($expense->domain, (int) $expense->location_id, $date, 'expense_date');

        $attributes = [
            'domain' => $expense->domain,
            'location_id' => $expense->location_id,
            'payment_card_type_id' => null,
            'direction' => 'out',
            'amount' => $expense->amount,
            'kind' => WalletCashMovement::KIND_EXPENSE,
            'notes' => Str::limit('Expense: '.$expense->description, 250),
            'movement_date' => $date,
            'user_id' => $expense->user_id,
        ];

        if ($movement) {
            $movement->update($attributes);
        } else {
            $expense->wallet_cash_movement_id = WalletCashMovement::query()->create($attributes)->id;
        }
    }

    private function guardExistingMovement(Expense $expense): void
    {
        $original = $expense->getOriginal();
        if (! empty($original['wallet_cash_movement_id']) && ! empty($original['location_id'])) {
            WalletShiftGuard::ensureDateNotClosed(
                $expense->domain,
                (int) $original['location_id'],
                $expense->getOriginal('expense_date')->toDateString(),
                'expense_date',
            );
        }
    }
}
