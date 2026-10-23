<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\Finance\SupplierBill;
use App\Models\Finance\SupplierPayment;
use App\Models\User;
use App\Support\FinanceAccess;
use App\Support\PaidFromAccount;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A payment on a supplier bill: Finance → Supplier bills → Pay, and Purchasing → Receive → Pay now.
 * Paid from the cash drawer means a ledger line out of that store's drawer. Call inside a
 * transaction: the bill is locked while it is paid.
 */
class SupplierPaymentService
{
    public function __construct(private readonly CashRegisterPosting $cashRegister) {}

    /**
     * @param  string  $prefix  Where the fields sit in the request, e.g. "pay_now." (empty: top level).
     * @return array<string, mixed>
     */
    public static function rules(string $domainSlug, string $prefix = ''): array
    {
        return [
            $prefix.'payment_date' => ['required', 'date', 'before_or_equal:today'],
            $prefix.'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            $prefix.'payment_method' => ['required', Rule::in(Expense::PAYMENT_METHODS)],
            // Paid from the cash drawer: which store's drawer the cash came out of. Warehouses have none.
            $prefix.'location_id' => [
                'nullable',
                'required_if:'.$prefix.'payment_method,cash_register',
                'integer',
                Rule::exists('inventory_locations', 'id')
                    ->where('domain', $domainSlug)
                    ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse')),
            ],
            $prefix.'financial_account_id' => PaidFromAccount::rules($domainSlug),
            $prefix.'reference_no' => ['nullable', 'string', 'max:80'],
            $prefix.'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated by rules(), without the prefix.
     * @param  string  $errorPrefix  Put on error keys, so they land on the right form field.
     */
    public function record(SupplierBill $bill, array $data, User $user, string $errorPrefix = ''): SupplierPayment
    {
        $bill = SupplierBill::query()->with('supplier:id,name')->lockForUpdate()->findOrFail($bill->id);
        if (round((float) $data['amount'], 2) > $bill->remaining) {
            throw ValidationException::withMessages([
                $errorPrefix.'amount' => 'Only ₱'.number_format($bill->remaining, 2).' is left to pay on this bill.',
            ]);
        }

        $locationId = $data['payment_method'] === 'cash_register' ? (int) $data['location_id'] : ($data['location_id'] ?? null);
        $restrictedTo = FinanceAccess::restrictedLocationId($user);
        if ($data['payment_method'] === 'cash_register' && $restrictedTo !== null && $locationId !== $restrictedTo) {
            throw ValidationException::withMessages([
                $errorPrefix.'location_id' => 'You can only pay from your own store\'s cash drawer.',
            ]);
        }

        $record = new SupplierPayment([
            'domain' => $bill->domain,
            'supplier_bill_id' => $bill->id,
            'location_id' => $locationId,
            'payment_date' => $data['payment_date'],
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'financial_account_id' => PaidFromAccount::keep($data['payment_method'], $data['financial_account_id'] ?? null),
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'user_id' => $user->id,
        ]);
        $this->cashRegister->sync(
            $record,
            'supplier_payment',
            'out',
            $record->payment_date->toDateString(),
            'Supplier payment: '.$bill->supplier->name.($bill->bill_number ? ' (bill '.$bill->bill_number.')' : ''),
            $errorPrefix.'payment_date',
        );
        $record->save();

        $bill->refreshPaid();

        return $record;
    }
}
