<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreditCustomerResource;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Domain;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        // The page asks for its own page size; cap it so a large one can't be requested.
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        $customers = $query->latest()->paginate($perPage)->withQueryString();

        // Calculate overdue amounts for each customer
        $customers->getCollection()->transform(function ($customer) {
            $customer->overdue_amount = $this->creditService->calculateOverdueAmount($customer);
            $customer->available_credit = $customer->getAvailableCredit();

            return $customer;
        });

        return Inertia::render('Credits/Index', [
            // A resource collection, so the totals land where the table looks for them. Handed the
            // paginator raw, the table saw no totals at all and fell back to paging the rows it had
            // been given ten at a time — so it claimed there were fifteen customers however many
            // there were, and everybody past the fifteenth was unreachable.
            'customers' => CreditCustomerResource::collection($customers),
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
    public function show(Domain $domain, Customer $customer)
    {
        // Ensure customer belongs to this domain
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        // Get payment history
        $paymentHistory = $customer->getCreditHistory(100);

        // Get outstanding invoices (unpaid credit transactions)
        $outstandingInvoices = $this->outstandingCharges($customer);

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
     * JSON: customer + unpaid credit lines for record-payment modal on credits index
     */
    public function outstandingInvoices(Domain $domain, Customer $customer)
    {
        if ($customer->domain !== $domain->name_slug) {
            abort(403, 'Customer does not belong to this domain');
        }

        $outstandingInvoices = $this->outstandingCharges($customer);

        return response()->json([
            'success' => true,
            'customer' => $customer->fresh(),
            'outstanding_invoices' => $outstandingInvoices,
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

        // Charging a customer by hand raises what they owe, so it is for whoever may set their credit.
        if ($request->input('transaction_type') === 'credit'
            && ! $request->user()->hasPermissionToRoute('credits.settings.update')) {
            abort(403, 'You are not allowed to charge credit to a customer.');
        }

        $validated = $request->validate([
            'transaction_type' => 'required|in:credit,payment,adjustment,refund',
            // An adjustment may lower the balance (a negative amount); everything else is above nothing.
            'amount' => $request->input('transaction_type') === 'adjustment'
                ? 'required|numeric|not_in:0'
                : 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|in:cash,card,e-wallet,bank',
            'installments' => 'nullable|array|min:2|max:60',
            'installments.*.due_date' => 'required|date|after_or_equal:today',
            'installments.*.amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'transaction_ids' => 'nullable|array',
            'transaction_ids.*' => [
                Rule::exists('credit_transactions', 'id')
                    ->where('customer_id', $customer->id)
                    ->where('transaction_type', 'credit')
                    ->whereNull('paid_at'),
            ],
            'due_date' => 'nullable|date|after_or_equal:today',
        ]);

        $installments = $validated['transaction_type'] === 'credit' ? ($validated['installments'] ?? []) : [];
        $this->validateInstallmentSchedule($installments, (float) $validated['amount']);

        $transactionIds = array_values(array_filter(
            $validated['transaction_ids'] ?? [],
            static fn ($id) => $id !== null && $id !== ''
        ));

        try {
            DB::beginTransaction();

            $customer->refresh();

            $transaction = $validated['transaction_type'] === 'credit'
                ? $this->creditService->processManualCharge(
                    customer: $customer,
                    amount: (float) $validated['amount'],
                    dueDate: $validated['due_date'] ?? null,
                    installments: $installments,
                    referenceNumber: $validated['reference_number'] ?? null,
                    notes: $validated['notes'] ?? null,
                )
                : $this->creditService->processPayment(
                customer: $customer,
                amount: (float) $validated['amount'],
                transactionIds: $transactionIds,
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

    /** A customer's unpaid charges, with their installment schedules and how late they are. */
    private function outstandingCharges(Customer $customer)
    {
        return $customer->creditTransactions()
            ->where('transaction_type', 'credit')
            ->whereNull('paid_at')
            ->with(['sale.saleItems.product', 'installments'])
            ->orderBy('due_date', 'asc')
            ->get()
            ->each->append('days_overdue');
    }

    /**
     * An installment schedule has to add up to the charge, with each payment due after the one before.
     *
     * @param  array<int, array{due_date: string, amount: mixed}>  $installments
     */
    private function validateInstallmentSchedule(array $installments, float $amount): void
    {
        if ($installments === []) {
            return;
        }

        $totalCents = array_sum(array_map(fn ($i) => (int) round((float) $i['amount'] * 100), $installments));
        if ($totalCents !== (int) round($amount * 100)) {
            throw ValidationException::withMessages([
                'installments' => 'The installments must add up to the amount charged ('.number_format($amount, 2).').',
            ]);
        }

        $previous = null;
        foreach ($installments as $installment) {
            $due = Carbon::parse($installment['due_date'])->startOfDay();
            if ($previous !== null && $due->lte($previous)) {
                throw ValidationException::withMessages([
                    'installments' => 'Each installment must be due after the one before it.',
                ]);
            }
            $previous = $due;
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
            ->with('installments')
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
