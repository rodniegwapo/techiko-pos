<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreditService
{
    /**
     * Process a credit sale
     */
    public function processCreditSale(Sale $sale, Customer $customer, float $amount): CreditTransaction
    {
        // Validate credit limit
        $this->checkCreditLimit($customer, $amount);

        // Calculate due date
        $dueDate = now()->addDays($customer->credit_terms_days);

        // Create credit transaction
        $transaction = $customer->addCreditTransaction(
            type: 'credit',
            amount: $amount,
            saleId: $sale->id,
            referenceNumber: $sale->invoice_number,
            notes: "Credit sale - Invoice: {$sale->invoice_number}",
            dueDate: $dueDate
        );

        // Update sale
        // Note: payment_status is already set to 'paid' by completeSale()
        // We only update credit-specific fields here
        $sale->update([
            'is_credit_sale' => true,
            'payment_method' => 'credit',
        ]);

        Log::info('Credit sale processed', [
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'amount' => $amount,
            'transaction_id' => $transaction->id,
        ]);

        return $transaction;
    }

    /**
     * Process a payment against credit
     */
    public function processPayment(Customer $customer, float $amount, array $transactionIds = [], ?string $paymentMethod = null, ?string $referenceNumber = null, ?string $notes = null, string $transactionType = 'payment'): CreditTransaction
    {
        $balance = (float) $customer->credit_balance;

        // For payment and refund types, validate amount doesn't exceed balance
        if (in_array($transactionType, ['payment', 'refund'], true) && abs((float) $amount) > $balance) {
            throw new \Exception('Payment amount cannot exceed credit balance.');
        }

        // A payment settles charges: the ones picked first, then the oldest still owed.
        if ($transactionType === 'payment') {
            $this->allocatePayment($customer, abs($amount), $transactionIds);
        }

        // Create transaction
        $transaction = $customer->addCreditTransaction(
            type: $transactionType,
            amount: $amount,
            saleId: null,
            referenceNumber: $referenceNumber,
            notes: $notes ?? ucfirst($transactionType).' transaction'.(! empty($transactionIds) ? ' - Applied to transactions' : ''),
            paymentMethod: $transactionType === 'payment' ? $paymentMethod : null
        );

        Log::info('Credit transaction processed', [
            'customer_id' => $customer->id,
            'amount' => $amount,
            'transaction_type' => $transactionType,
            'transaction_id' => $transaction->id,
            'applied_to' => $transactionIds,
        ]);

        return $transaction;
    }

    /**
     * Charge a customer on credit by hand (not from a sale), due on one date or split into installments.
     *
     * @param  array<int, array{due_date: string, amount: float|int|string}>  $installments
     */
    public function processManualCharge(
        Customer $customer,
        float $amount,
        ?string $dueDate = null,
        array $installments = [],
        ?string $referenceNumber = null,
        ?string $notes = null,
    ): CreditTransaction {
        $this->checkCreditLimit($customer, $amount);

        // The charge is due when its last installment is.
        if ($installments !== []) {
            $dueDate = end($installments)['due_date'];
        }

        $transaction = $customer->addCreditTransaction(
            type: 'credit',
            amount: $amount,
            referenceNumber: $referenceNumber,
            notes: $notes ?? 'Manual credit charge',
            dueDate: $dueDate !== null ? Carbon::parse($dueDate) : null
        );

        foreach (array_values($installments) as $i => $installment) {
            $transaction->installments()->create([
                'seq' => $i + 1,
                'due_date' => $installment['due_date'],
                'amount' => round((float) $installment['amount'], 2),
            ]);
        }

        Log::info('Manual credit charge recorded', [
            'customer_id' => $customer->id,
            'amount' => $amount,
            'installments' => count($installments),
            'transaction_id' => $transaction->id,
        ]);

        return $transaction->load('installments');
    }

    /**
     * Put a payment against what the customer owes: the charges picked first, then the oldest
     * still unpaid. Within a charge paid in installments, the earliest installment is paid first.
     * A charge, or an installment, is marked paid only once it is paid in full.
     */
    protected function allocatePayment(Customer $customer, float $amount, array $transactionIds = []): void
    {
        // Work in centavos so partial payments never leave a stray fraction behind.
        $left = (int) round($amount * 100);

        $unpaid = CreditTransaction::query()
            ->where('customer_id', $customer->id)
            ->where('transaction_type', 'credit')
            ->whereNull('paid_at')
            ->with('installments')
            ->orderByRaw('due_date IS NULL, due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $picked = array_map('intval', $transactionIds);
        $charges = $unpaid->sortBy(fn ($c) => in_array($c->id, $picked, true) ? 0 : 1)->values();

        $settledSaleIds = [];

        foreach ($charges as $charge) {
            if ($left <= 0) {
                break;
            }

            $owed = self::cents($charge->amount) - self::cents($charge->paid_amount);
            $applied = min($left, max(0, $owed));
            if ($applied === 0) {
                continue;
            }

            $toInstallments = $applied;
            foreach ($charge->installments as $installment) {
                if ($toInstallments <= 0) {
                    break;
                }
                if ($installment->paid_at !== null) {
                    continue;
                }

                $due = self::cents($installment->amount) - self::cents($installment->paid_amount);
                $part = min($toInstallments, max(0, $due));
                $toInstallments -= $part;

                $installment->update([
                    'paid_amount' => (self::cents($installment->paid_amount) + $part) / 100,
                    'paid_at' => $part >= $due ? now() : null,
                ]);
            }

            $left -= $applied;
            $fullyPaid = $applied >= $owed;

            $charge->update([
                'paid_amount' => (self::cents($charge->paid_amount) + $applied) / 100,
                'paid_at' => $fullyPaid ? now() : null,
            ]);

            if ($fullyPaid && $charge->sale_id) {
                $settledSaleIds[] = $charge->sale_id;
            }
        }

        // A credit sale counts as paid once every charge on it is.
        foreach (array_unique($settledSaleIds) as $saleId) {
            $stillOwed = CreditTransaction::where('sale_id', $saleId)
                ->where('transaction_type', 'credit')
                ->whereNull('paid_at')
                ->exists();

            if (! $stillOwed) {
                Sale::whereKey($saleId)->update(['payment_status' => 'paid']);
            }
        }
    }

    private static function cents($value): int
    {
        return (int) round((float) $value * 100);
    }

    /**
     * Check if customer can make a credit purchase
     */
    public function checkCreditLimit(Customer $customer, float $amount): bool
    {
        // Validation errors (422), not server errors: these are expected outcomes of a cashier's input.
        if (! $customer->credit_enabled) {
            throw ValidationException::withMessages([
                'customer_id' => 'Credit is not enabled for this customer.',
            ]);
        }

        if (! $customer->canPurchaseOnCredit($amount)) {
            throw ValidationException::withMessages([
                'customer_id' => 'Credit limit exceeded. Available credit: '.number_format($customer->getAvailableCredit(), 2),
            ]);
        }

        return true;
    }

    /**
     * Calculate overdue amount for a customer
     */
    public function calculateOverdueAmount(Customer $customer): float
    {
        return $customer->getTotalOverdueAmount();
    }

    /**
     * Send overdue alert (logs for now, can be extended to notifications)
     */
    public function sendOverdueAlert(Customer $customer): void
    {
        $overdueAmount = $this->calculateOverdueAmount($customer);
        $overdueTransactions = $customer->getOverdueTransactions();

        if ($overdueAmount > 0) {
            Log::warning('Overdue account alert', [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'overdue_amount' => $overdueAmount,
                'overdue_count' => $overdueTransactions->count(),
            ]);
        }
    }

    /**
     * Get all overdue accounts
     */
    public function getOverdueAccounts(string $domain): array
    {
        $customers = Customer::forDomain($domain)
            ->where('credit_enabled', true)
            ->where('credit_balance', '>', 0)
            ->get();

        $overdueAccounts = [];

        foreach ($customers as $customer) {
            $overdueAmount = $this->calculateOverdueAmount($customer);
            if ($overdueAmount > 0) {
                $overdueTransactions = $customer->getOverdueTransactions();
                $overdueAccounts[] = [
                    'customer' => $customer,
                    'overdue_amount' => $overdueAmount,
                    'overdue_count' => $overdueTransactions->count(),
                    'oldest_overdue_date' => $overdueTransactions->first()?->due_date,
                ];
            }
        }

        return $overdueAccounts;
    }
}
