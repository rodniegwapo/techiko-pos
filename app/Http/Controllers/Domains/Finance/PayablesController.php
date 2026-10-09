<?php

namespace App\Http\Controllers\Domains\Finance;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Finance\Supplier;
use App\Models\Finance\SupplierBill;
use App\Models\Finance\SupplierPayment;
use App\Models\InventoryLocation;
use App\Services\Finance\CashRegisterPosting;
use App\Services\Finance\FinancialReportService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Who the business owes: suppliers, the bills they send and the payments made on them.
 */
class PayablesController extends Controller
{
    private const STATUS_FILTERS = ['open', 'overdue', 'paid', 'all'];

    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly CashRegisterPosting $cashRegister,
    ) {}

    public function index(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        ExpenseCategory::ensureDefaults($slug);

        $validated = $request->validate([
            'period' => ['nullable', Rule::in(FinancialReportService::PERIODS)],
            'start_date' => ['nullable', 'required_if:period,custom', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in(self::STATUS_FILTERS)],
            'supplier_id' => ['nullable', 'integer'],
        ]);
        $period = $this->reports->resolvePeriod($validated['period'] ?? 'month', $validated['start_date'] ?? null, $validated['end_date'] ?? null);
        $status = $validated['status'] ?? 'open';
        $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null;

        $bills = SupplierBill::query()
            ->forDomain($slug)
            ->when($supplierId, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($status === 'open', fn ($q) => $q->open())
            ->when($status === 'overdue', fn ($q) => $q->overdue())
            ->when($status === 'paid', fn ($q) => $q->whereColumn('paid_amount', '>=', 'amount'))
            ->with([
                'supplier:id,name',
                'category:id,name',
                'location:id,name',
                'payments' => fn ($q) => $q->with('location:id,name'),
            ])
            // Open bills: soonest due first; otherwise newest first.
            ->when(in_array($status, ['open', 'overdue'], true),
                fn ($q) => $q->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id'),
                fn ($q) => $q->orderByDesc('bill_date')->orderByDesc('id'))
            ->paginate(25)
            ->withQueryString();

        $suppliers = Supplier::query()
            ->forDomain($slug)
            ->withSum(['bills as total_billed' => fn ($q) => $q], 'amount')
            ->withSum(['bills as total_paid' => fn ($q) => $q], 'paid_amount')
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $s) => [
                ...$s->only(['id', 'name', 'contact_person', 'phone', 'email', 'payment_terms_days', 'notes', 'is_active']),
                'balance' => round((float) $s->total_billed - (float) $s->total_paid, 2),
            ]);

        return Inertia::render('Finance/Payables', [
            'filters' => [
                ...$this->reports->describePeriod($period),
                'status' => $status,
                'supplier_id' => $supplierId,
            ],
            'payables' => $this->reports->payables($slug, $period['start'], $period['end'], $period['previous_start'], $period['previous_end']),
            'bills' => $bills,
            'suppliers' => $suppliers,
            'categories' => ExpenseCategory::query()->forDomain($slug)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'locations' => InventoryLocation::query()->forDomain($slug)->active()->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'defaultLocationId' => $request->user()->location_id,
            'aiEnabled' => (string) config('services.anthropic.key') !== '',
            'domainName' => $domain->name,
        ]);
    }

    public function storeSupplier(Request $request, Domain $domain): RedirectResponse
    {
        $validated = $this->validateSupplier($request, $domain);
        Supplier::query()->create([...$validated, 'domain' => $domain->name_slug]);

        return back()->with('success', 'Supplier added.');
    }

    public function updateSupplier(Request $request, Domain $domain, Supplier $supplier): RedirectResponse
    {
        abort_unless($supplier->domain === $domain->name_slug, 404);
        $supplier->update($this->validateSupplier($request, $domain, $supplier));

        return back()->with('success', 'Supplier updated.');
    }

    public function storeBill(Request $request, Domain $domain): RedirectResponse
    {
        $validated = $this->validateBill($request, $domain);

        SupplierBill::query()->create([
            ...$this->billFields($domain, $validated),
            'domain' => $domain->name_slug,
            'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Bill recorded.');
    }

    public function updateBill(Request $request, Domain $domain, SupplierBill $bill): RedirectResponse
    {
        abort_unless($bill->domain === $domain->name_slug, 404);
        $validated = $this->validateBill($request, $domain);

        if ((float) $validated['amount'] < (float) $bill->paid_amount) {
            throw ValidationException::withMessages([
                'amount' => 'The bill can\'t be less than the ₱'.number_format((float) $bill->paid_amount, 2).' already paid on it.',
            ]);
        }

        $bill->update($this->billFields($domain, $validated));
        $bill->refreshPaid();

        return back()->with('success', 'Bill updated.');
    }

    public function destroyBill(Domain $domain, SupplierBill $bill): RedirectResponse
    {
        abort_unless($bill->domain === $domain->name_slug, 404);

        if ($bill->payments()->exists()) {
            throw ValidationException::withMessages([
                'bill' => 'This bill has payments. Delete its payments first.',
            ]);
        }
        $bill->delete();

        return back()->with('success', 'Bill deleted.');
    }

    public function storePayment(Request $request, Domain $domain, SupplierBill $bill): RedirectResponse
    {
        abort_unless($bill->domain === $domain->name_slug, 404);

        $validated = $request->validate([
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payment_method' => ['required', Rule::in(Expense::PAYMENT_METHODS)],
            // Paid from the cash register: whose drawer the cash came out of.
            'location_id' => [
                'nullable',
                'required_if:payment_method,cash_register',
                'integer',
                Rule::exists('inventory_locations', 'id')->where('domain', $domain->name_slug),
            ],
            'reference_no' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $domain, $bill, $validated) {
            $bill = SupplierBill::query()->with('supplier:id,name')->lockForUpdate()->findOrFail($bill->id);
            if (round((float) $validated['amount'], 2) > $bill->remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Only ₱'.number_format($bill->remaining, 2).' is left to pay on this bill.',
                ]);
            }

            $record = new SupplierPayment([
                'domain' => $domain->name_slug,
                'supplier_bill_id' => $bill->id,
                'location_id' => $validated['location_id'] ?? null,
                'payment_date' => $validated['payment_date'],
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'reference_no' => $validated['reference_no'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $request->user()->id,
            ]);
            $this->cashRegister->sync(
                $record,
                'supplier_payment',
                'out',
                $record->payment_date->toDateString(),
                'Supplier payment: '.$bill->supplier->name.($bill->bill_number ? ' (bill '.$bill->bill_number.')' : ''),
                'payment_date',
            );
            $record->save();

            $bill->refreshPaid();
        });

        return back()->with('success', 'Payment recorded.');
    }

    public function destroyPayment(Domain $domain, SupplierPayment $payment): RedirectResponse
    {
        abort_unless($payment->domain === $domain->name_slug, 404);

        DB::transaction(function () use ($payment) {
            $bill = $payment->bill;
            $this->cashRegister->delete($payment, 'payment_date');
            $bill->refreshPaid();
        });

        return back()->with('success', 'Payment deleted.');
    }

    /** @return array<string, mixed> */
    private function validateSupplier(Request $request, Domain $domain, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('suppliers', 'name')->where('domain', $domain->name_slug)->ignore($supplier?->id)],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateBill(Request $request, Domain $domain): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('domain', $domain->name_slug)],
            'bill_number' => ['nullable', 'string', 'max:60'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'bill_type' => ['required', Rule::in(['inventory', 'expense'])],
            'expense_category_id' => [
                'nullable',
                'required_if:bill_type,expense',
                'integer',
                Rule::exists('expense_categories', 'id')->where('domain', $domain->name_slug),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'location_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function billFields(Domain $domain, array $validated): array
    {
        $locationId = isset($validated['location_id']) ? (int) $validated['location_id'] : null;
        if ($locationId && ! InventoryLocation::query()->forDomain($domain->name_slug)->whereKey($locationId)->exists()) {
            $locationId = null;
        }

        // No due date given: the supplier's usual payment terms.
        $dueDate = $validated['due_date'] ?? Carbon::parse($validated['bill_date'])
            ->addDays(Supplier::query()->whereKey($validated['supplier_id'])->value('payment_terms_days') ?? 30)
            ->toDateString();

        return [
            'supplier_id' => $validated['supplier_id'],
            'bill_number' => $validated['bill_number'] ?? null,
            'bill_date' => $validated['bill_date'],
            'due_date' => $dueDate,
            'bill_type' => $validated['bill_type'],
            'expense_category_id' => $validated['bill_type'] === 'expense' ? $validated['expense_category_id'] : null,
            'amount' => $validated['amount'],
            'location_id' => $locationId,
            'notes' => $validated['notes'] ?? null,
        ];
    }
}
