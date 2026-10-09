<?php

namespace App\Http\Controllers\Domains;

use App\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryLocation;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\RecurringExpenseService;
use App\Support\ExpenseReceiptStorage;
use App\Support\FinanceAccess;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Business expenses for the Profit & Loss report. Admins work across all stores and business-wide;
 * managers only record and edit their own store's expenses (business-wide ones are read-only to them).
 */
class ExpenseController extends Controller
{
    /** Filter value for expenses not tied to a store. */
    private const BUSINESS_WIDE = 'business';

    public function __construct(
        private ExpenseService $expenses,
        private RecurringExpenseService $recurring,
    ) {}

    public function index(Request $request, Domain $domain)
    {
        $user = $this->authorizeFinance($request);
        ExpenseCategory::ensureDefaults($domain->name_slug);
        // No cron is guaranteed, so catch up any recurring expenses that fell due since the last visit.
        $this->recurring->generateDue($domain->name_slug);

        $filters = $this->resolveFilters($request, $domain);
        $query = $this->baseQuery($domain, $user, $filters);

        $total = (float) (clone $query)->sum('amount');
        $count = (clone $query)->count();
        $categoryNames = ExpenseCategory::query()->forDomain($domain->name_slug)->pluck('name', 'id');
        $byCategory = (clone $query)
            ->groupBy('expense_category_id')
            ->selectRaw('expense_category_id, SUM(amount) as total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name' => $categoryNames[$row->expense_category_id] ?? 'Other',
                'total' => round((float) $row->total, 2),
            ]);

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $items = (clone $query)
            ->with(['category:id,name', 'location:id,name', 'user:id,name'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Expense $expense) => $this->row($expense, $user));

        return Inertia::render('Expenses/Index', [
            'items' => $items,
            'filters' => [
                'start_date' => $filters['start']->toDateString(),
                'end_date' => $filters['end']->toDateString(),
                'location' => $filters['location'],
                'category_id' => $filters['category_id'],
                'payment_method' => $filters['payment_method'],
                'search' => $filters['search'],
            ],
            'summary' => [
                'total' => round($total, 2),
                'count' => $count,
                'by_category' => $byCategory,
            ],
            'options' => [
                'locations' => $this->allowedLocations($domain, $user)->map->only(['id', 'name'])->values(),
                'categories' => ExpenseCategory::query()
                    ->forDomain($domain->name_slug)
                    ->withCount('expenses')
                    ->orderBy('name')
                    ->get(['id', 'name', 'type', 'is_active', 'is_default']),
                'payment_methods' => Expense::PAYMENT_METHODS,
            ],
            'canSeeAllStores' => FinanceAccess::canSeeAllStores($user),
            'restrictedLocationId' => FinanceAccess::restrictedLocationId($user),
            'recurring' => $this->recurringRows($domain, $user),
            // New expenses default to the store picked in the header.
            'activeLocationId' => Helpers::getActiveLocation($domain)?->id,
        ]);
    }

    public function store(Request $request, Domain $domain)
    {
        $user = $this->authorizeFinance($request);
        $data = $this->validated($request, $domain, $user);

        $repeat = $request->validate(['repeat' => ['nullable', Rule::in(['none', ...RecurringExpense::FREQUENCIES])]])['repeat'] ?? 'none';

        DB::transaction(function () use ($data, $domain, $user, $request, $repeat) {
            $expense = $this->expenses->create(
                $data + ['domain' => $domain->name_slug, 'user_id' => $user->id],
                $request->file('receipt'),
            );

            if ($repeat !== 'none') {
                $this->startRecurringFrom($expense, $repeat);
            }
        });

        return redirect()->back()->with('success', $repeat === 'none' ? 'Expense saved.' : 'Expense saved and set to repeat '.$repeat.'.');
    }

    public function update(Request $request, Domain $domain, Expense $expense)
    {
        $user = $this->authorizeFinance($request);
        $this->ensureCanEdit($domain, $user, $expense);
        $data = $this->validated($request, $domain, $user);

        $this->expenses->update($expense, $data, $request->file('receipt'), $request->boolean('remove_receipt'));

        return redirect()->back()->with('success', 'Expense updated.');
    }

    public function destroy(Request $request, Domain $domain, Expense $expense)
    {
        $user = $this->authorizeFinance($request);
        $this->ensureCanEdit($domain, $user, $expense);

        $this->expenses->delete($expense);

        return redirect()->back()->with('success', 'Expense deleted.');
    }

    public function receipt(Request $request, Domain $domain, Expense $expense)
    {
        $user = $this->authorizeFinance($request);
        $this->ensureCanView($domain, $user, $expense);
        abort_unless($expense->receipt_path, 404);

        return ExpenseReceiptStorage::response($expense->receipt_path);
    }

    public function export(Request $request, Domain $domain): StreamedResponse
    {
        $user = $this->authorizeFinance($request);
        $filters = $this->resolveFilters($request, $domain);
        $query = $this->baseQuery($domain, $user, $filters)
            ->with(['category:id,name', 'location:id,name', 'user:id,name'])
            ->orderBy('expense_date')
            ->orderBy('id');

        $filename = sprintf(
            'expenses-%s-%s-%s.csv',
            preg_replace('/[^a-zA-Z0-9_-]+/', '-', $domain->name_slug),
            $filters['start']->format('Y-m-d'),
            $filters['end']->format('Y-m-d')
        );

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['date', 'category', 'description', 'payee', 'store', 'paid_from', 'reference_no', 'amount', 'recorded_by', 'notes']);

            foreach ($query->lazy(500) as $expense) {
                fputcsv($out, [
                    $expense->expense_date->toDateString(),
                    $expense->category?->name,
                    $expense->description,
                    $expense->payee,
                    $expense->location?->name ?? 'Business-wide',
                    $expense->payment_method,
                    $expense->reference_no,
                    number_format((float) $expense->amount, 2, '.', ''),
                    $expense->user?->name,
                    $expense->notes,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** "Repeat monthly/weekly" on a new expense: this one is the first run, the template books the rest. */
    private function startRecurringFrom(Expense $expense, string $frequency): void
    {
        $date = $expense->expense_date;
        $template = RecurringExpense::query()->make([
            'domain' => $expense->domain,
            'location_id' => $expense->location_id,
            'expense_category_id' => $expense->expense_category_id,
            'amount' => $expense->amount,
            'description' => $expense->description,
            'payee' => $expense->payee,
            'payment_method' => $expense->payment_method,
            'frequency' => $frequency,
            'day_of_month' => $frequency === 'monthly' ? $date->day : null,
            'day_of_week' => $frequency === 'weekly' ? $date->dayOfWeek : null,
            'start_date' => $date->toDateString(),
            'user_id' => $expense->user_id,
        ]);
        $template->next_run_date = $template->runAfter($date);
        $template->save();

        $expense->update(['recurring_expense_id' => $template->id]);
    }

    private function recurringRows(Domain $domain, User $user)
    {
        $restrictedTo = FinanceAccess::restrictedLocationId($user);

        return RecurringExpense::query()
            ->forDomain($domain->name_slug)
            ->when(! FinanceAccess::canSeeAllStores($user), fn ($q) => $q->where(
                fn ($q) => $q->where('location_id', $restrictedTo)->orWhereNull('location_id')
            ))
            ->with(['category:id,name', 'location:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('next_run_date')
            ->get()
            ->map(fn (RecurringExpense $r) => [
                'id' => $r->id,
                'description' => $r->description,
                'payee' => $r->payee,
                'amount' => round((float) $r->amount, 2),
                'payment_method' => $r->payment_method,
                'expense_category_id' => $r->expense_category_id,
                'category_name' => $r->category?->name,
                'location_id' => $r->location_id,
                'location_name' => $r->location?->name,
                'frequency' => $r->frequency,
                'day_of_month' => $r->day_of_month,
                'day_of_week' => $r->day_of_week,
                'schedule_label' => $r->scheduleLabel(),
                'start_date' => $r->start_date->toDateString(),
                'end_date' => $r->end_date?->toDateString(),
                'next_run_date' => $r->next_run_date->toDateString(),
                'is_active' => $r->is_active,
                'can_edit' => FinanceAccess::canSeeAllStores($user)
                    || ($r->location_id !== null && (int) $r->location_id === $restrictedTo),
            ]);
    }

    private function authorizeFinance(Request $request): User
    {
        $user = $request->user();
        abort_unless(FinanceAccess::canManage($user), 403);

        return $user;
    }

    private function validated(Request $request, Domain $domain, User $user): array
    {
        $data = $request->validate([
            'expense_category_id' => [
                'required', 'integer',
                Rule::exists('expense_categories', 'id')->where('domain', $domain->name_slug)->where('is_active', true),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::in(Expense::PAYMENT_METHODS)],
            'location_id' => ['nullable', 'integer'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'remove_receipt' => ['nullable', 'boolean'],
        ]);

        $locationId = FinanceAccess::restrictedLocationId($user)
            ?? (isset($data['location_id']) ? (int) $data['location_id'] : null);

        if (! FinanceAccess::canSeeAllStores($user) && $locationId === null) {
            throw ValidationException::withMessages(['location_id' => 'Your account has no store assigned.']);
        }
        if ($locationId !== null && ! InventoryLocation::query()->forDomain($domain->name_slug)->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Choose a store from this business.']);
        }
        if ($data['payment_method'] === 'cash_register' && $locationId === null) {
            throw ValidationException::withMessages(['location_id' => 'Choose the store whose cash register paid this expense.']);
        }

        unset($data['receipt'], $data['remove_receipt']);
        $data['location_id'] = $locationId;

        return $data;
    }

    /**
     * @return array{start: Carbon, end: Carbon, location: int|string|null, category_id: ?int, payment_method: ?string, search: ?string}
     */
    private function resolveFilters(Request $request, Domain $domain): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'location' => ['nullable', 'string', 'max:20'],
            'category_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(Expense::PAYMENT_METHODS)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $start = isset($validated['start_date']) ? Carbon::parse($validated['start_date'])->startOfDay() : now()->startOfMonth();
        $end = isset($validated['end_date']) ? Carbon::parse($validated['end_date'])->endOfDay() : now()->endOfMonth();

        $location = $validated['location'] ?? null;
        if ($location !== null && $location !== self::BUSINESS_WIDE) {
            $location = ctype_digit($location)
                && InventoryLocation::query()->forDomain($domain->name_slug)->whereKey((int) $location)->exists()
                ? (int) $location
                : null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'location' => $location,
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'payment_method' => $validated['payment_method'] ?? null,
            'search' => isset($validated['search']) ? trim($validated['search']) : null,
        ];
    }

    private function baseQuery(Domain $domain, User $user, array $filters): Builder
    {
        $restrictedTo = FinanceAccess::restrictedLocationId($user);

        return Expense::query()
            ->forDomain($domain->name_slug)
            ->whereBetween('expense_date', [$filters['start']->toDateString(), $filters['end']->toDateString()])
            // Managers see their own store plus business-wide expenses.
            ->when(! FinanceAccess::canSeeAllStores($user), fn ($q) => $q->where(
                fn ($q) => $q->where('location_id', $restrictedTo)->orWhereNull('location_id')
            ))
            ->when($filters['location'] === self::BUSINESS_WIDE, fn ($q) => $q->whereNull('location_id'))
            ->when(is_int($filters['location']), fn ($q) => $q->where('location_id', $filters['location']))
            ->when($filters['category_id'], fn ($q, $id) => $q->where('expense_category_id', $id))
            ->when($filters['payment_method'], fn ($q, $method) => $q->where('payment_method', $method))
            ->when($filters['search'], function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                        ->orWhere('payee', 'like', "%{$search}%")
                        ->orWhere('reference_no', 'like', "%{$search}%");
                });
            });
    }

    private function allowedLocations(Domain $domain, User $user)
    {
        $restrictedTo = FinanceAccess::restrictedLocationId($user);

        return InventoryLocation::query()
            ->forDomain($domain->name_slug)
            ->active()
            ->when(! FinanceAccess::canSeeAllStores($user), fn ($q) => $q->whereKey($restrictedTo ?? 0))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function ensureCanView(Domain $domain, User $user, Expense $expense): void
    {
        abort_if($expense->domain !== $domain->name_slug, 404);

        if (! FinanceAccess::canSeeAllStores($user)) {
            abort_unless(
                $expense->location_id === null || (int) $expense->location_id === FinanceAccess::restrictedLocationId($user),
                404
            );
        }
    }

    private function ensureCanEdit(Domain $domain, User $user, Expense $expense): void
    {
        $this->ensureCanView($domain, $user, $expense);

        abort_unless($this->canEdit($user, $expense), 403);
    }

    private function canEdit(User $user, Expense $expense): bool
    {
        return FinanceAccess::canSeeAllStores($user)
            || ($expense->location_id !== null && (int) $expense->location_id === FinanceAccess::restrictedLocationId($user));
    }

    private function row(Expense $expense, User $user): array
    {
        return [
            'id' => $expense->id,
            'expense_date' => $expense->expense_date->toDateString(),
            'description' => $expense->description,
            'payee' => $expense->payee,
            'amount' => round((float) $expense->amount, 2),
            'payment_method' => $expense->payment_method,
            'reference_no' => $expense->reference_no,
            'notes' => $expense->notes,
            'expense_category_id' => $expense->expense_category_id,
            'category_name' => $expense->category?->name,
            'location_id' => $expense->location_id,
            'location_name' => $expense->location?->name,
            'recorded_by' => $expense->user?->name,
            'has_receipt' => (bool) $expense->receipt_path,
            'in_wallet_ledger' => (bool) $expense->wallet_cash_movement_id,
            'is_recurring' => (bool) $expense->recurring_expense_id,
            'can_edit' => $this->canEdit($user, $expense),
        ];
    }
}
