<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Domain;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CreditController extends Controller
{
    protected $creditService;

    public function __construct(CreditService $creditService)
    {
        $this->creditService = $creditService;
    }

    /**
     * Display a listing of customers with credit balances
     */
    public function index(Request $request, Domain $domain)
    {
        $query = Customer::query()
            ->where('domain', $domain->name_slug)
            ->when($request->search, function ($q, $search) {
                return $q->search($search);
            })
            ->when($request->status, function ($q, $status) {
                return match ($status) {
                    'overdue' => $q->where('credit_enabled', true)
                        ->where('credit_balance', '>', 0)
                        ->whereHas('creditTransactions', function ($query) {
                            $query->overdue();
                        }),
                    'at_limit' => $q->where('credit_enabled', true)
                        ->whereRaw('credit_balance >= credit_limit'),
                    'good_standing' => $q->where('credit_enabled', true)
                        ->where('credit_balance', '>', 0)
                        ->whereDoesntHave('creditTransactions', function ($query) {
                            $query->overdue();
                        }),
                    'enabled' => $q->where('credit_enabled', true),
                    'disabled' => $q->where('credit_enabled', false),
                    default => $q,
                };
            });

        $customers = $query->latest()->paginate(15);

        // Calculate overdue amounts for each customer
        $customers->getCollection()->transform(function ($customer) {
            $customer->overdue_amount = $this->creditService->calculateOverdueAmount($customer);
            $customer->available_credit = $customer->getAvailableCredit();

            return $customer;
        });

        return Inertia::render('Credits/Index', [
            'customers' => $customers,
            'domain' => $domain,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Get overdue accounts
     */
    public function overdue(Request $request, Domain $domain)
    {
        $overdueAccounts = $this->creditService->getOverdueAccounts($domain->name_slug);

        return response()->json([
            'success' => true,
            'data' => $overdueAccounts,
        ]);
    }

    /**
     * Show customer credit details
     */
    public function show(Request $request, Domain $domain, Customer $customer)
    {
        // Ensure customer belongs to this domain
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        // Get payment history
        $paymentHistory = $customer->getCreditHistory(100);

        // Get outstanding invoices (unpaid credit transactions)
        $outstandingInvoices = $customer->creditTransactions()
            ->where('transaction_type', 'credit')
            ->whereNull('paid_at')
            ->with('sale')
            ->orderBy('due_date', 'asc')
            ->get();

        // Get overdue transactions
        $overdueTransactions = $customer->getOverdueTransactions();

        return Inertia::render('Credits/Show', [
            'customer' => $customer,
            'domain' => $domain,
            'paymentHistory' => $paymentHistory,
            'outstandingInvoices' => $outstandingInvoices,
            'overdueTransactions' => $overdueTransactions,
            'overdueAmount' => $this->creditService->calculateOverdueAmount($customer),
            'availableCredit' => $customer->getAvailableCredit(),
        ]);
    }

    /**
     * Store a new credit transaction (payment or adjustment)
     */
    public function storeTransaction(Request $request, Domain $domain, Customer $customer)
    {
        // Ensure customer belongs to this domain
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        $validated = $request->validate([
            'transaction_type' => 'required|in:payment,adjustment,refund',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'transaction_ids' => 'nullable|array',
            'transaction_ids.*' => 'exists:credit_transactions,id',
            'due_date' => 'nullable|date',
        ]);

        try {
            DB::beginTransaction();

            $dueDate = $validated['due_date'] ? new \DateTime($validated['due_date']) : null;

            $transaction = $this->creditService->processPayment(
                customer: $customer,
                amount: $validated['amount'],
                transactionIds: $validated['transaction_ids'] ?? [],
                paymentMethod: $validated['payment_method'] ?? null,
                referenceNumber: $validated['reference_number'] ?? null,
                notes: $validated['notes'] ?? null,
                transactionType: $validated['transaction_type']
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction recorded successfully',
                'transaction' => $transaction,
                'customer' => $customer->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update an existing credit transaction
     */
    public function updateTransaction(Request $request, Domain $domain, CreditTransaction $transaction)
    {
        // Ensure transaction belongs to domain
        if ($transaction->domain !== $domain->name_slug) {
            abort(403, 'Transaction does not belong to this domain');
        }

        $validated = $request->validate([
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        try {
            $transaction->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Transaction updated successfully',
                'transaction' => $transaction->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get customer credit history
     */
    public function history(Request $request, Domain $domain, Customer $customer)
    {
        // Ensure customer belongs to this domain
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        $query = $customer->creditTransactions()
            ->when($request->type, function ($q, $type) {
                return $q->where('transaction_type', $type);
            })
            ->when($request->date_from, function ($q, $date) {
                return $q->whereDate('created_at', '>=', $date);
            })
            ->when($request->date_to, function ($q, $date) {
                return $q->whereDate('created_at', '<=', $date);
            });

        $history = $query->orderBy('created_at', 'desc')->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Update customer credit limit and settings
     */
    public function updateCreditSettings(Request $request, Domain $domain, Customer $customer)
    {
        // Ensure customer belongs to this domain
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        $validated = $request->validate([
            'credit_limit' => 'required|numeric|min:0',
            'credit_enabled' => 'boolean',
            'credit_terms_days' => 'required|integer|min:1|max:365',
        ]);

        $customer->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Credit settings updated successfully',
            'customer' => $customer->fresh(),
        ]);
    }
}
