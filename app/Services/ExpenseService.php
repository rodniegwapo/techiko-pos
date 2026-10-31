<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\WalletCashMovement;
use App\Services\Finance\CashRegisterPosting;
use App\Support\ExpenseReceiptStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Saving an expense paid from the cash register also posts a cash-out to that store's wallet
 * ledger, so the drawer's expected cash stays right; paid from a bank or e-wallet account linked
 * to a payment channel, the cash-out goes on that channel. The two are kept in step on edit and delete.
 */
class ExpenseService
{
    public function __construct(private readonly CashRegisterPosting $posting) {}

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
        $this->posting->sync(
            $expense,
            WalletCashMovement::KIND_EXPENSE,
            'out',
            $expense->expense_date->toDateString(),
            'Expense: '.$expense->description,
            'expense_date',
        );
    }

    private function guardExistingMovement(Expense $expense): void
    {
        $this->posting->guardExisting($expense, 'expense_date');
    }
}
