<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\CreditTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $sale->update([
            'is_credit_sale' => true,
            'payment_method' => 'credit',
            'payment_status' => 'pending',
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
        // For payment and refund types, validate amount doesn't exceed balance
        if (in_array($transactionType, ['payment', 'refund']) && abs($amount) > $customer->credit_balance) {
            throw new \Exception('Payment amount cannot exceed credit balance.');
        }

        // If specific transactions are provided, mark them as paid
        if (!empty($transactionIds) && $transactionType === 'payment') {
            $transactions = CreditTransaction::whereIn('id', $transactionIds)
                ->where('customer_id', $customer->id)
                ->where('transaction_type', 'credit')
                ->whereNull('paid_at')
                ->get();

            foreach ($transactions as $transaction) {
                $transaction->markAsPaid();
            }

            // Update related sales if fully paid
            foreach ($transactions as $transaction) {
                if ($transaction->sale_id) {
                    $sale = Sale::find($transaction->sale_id);
                    if ($sale) {
                        // Check if all credit transactions for this sale are paid
                        $unpaidTransactions = CreditTransaction::where('sale_id', $sale->id)
                            ->where('transaction_type', 'credit')
                            ->whereNull('paid_at')
                            ->count();

                        if ($unpaidTransactions === 0) {
                            $sale->update(['payment_status' => 'paid']);
                        }
                    }
                }
            }
        }

        // Create transaction
        $transaction = $customer->addCreditTransaction(
            type: $transactionType,
            amount: $amount,
            saleId: null,
            referenceNumber: $referenceNumber,
            notes: $notes ?? ucfirst($transactionType) . " transaction" . (!empty($transactionIds) ? " - Applied to transactions" : "")
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
     * Check if customer can make a credit purchase
     */
    public function checkCreditLimit(Customer $customer, float $amount): bool
    {
        if (!$customer->credit_enabled) {
            throw new \Exception('Credit is not enabled for this customer.');
        }

        if (!$customer->canPurchaseOnCredit($amount)) {
            throw new \Exception('Credit limit exceeded. Available credit: ' . number_format($customer->getAvailableCredit(), 2));
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
