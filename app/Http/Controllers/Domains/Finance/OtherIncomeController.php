<?php

namespace App\Http\Controllers\Domains\Finance;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\Finance\OtherIncome;
use App\Models\InventoryLocation;
use App\Services\Finance\CashRegisterPosting;
use App\Services\Finance\FinancialReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Money earned outside of sales (renting out space, a supplier rebate, interest…). It shows on
 * the income statement as other income; put into the cash register, it also posts to the ledger.
 */
class OtherIncomeController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly CashRegisterPosting $cashRegister,
    ) {}

    public function index(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(FinancialReportService::PERIODS)],
            'start_date' => ['nullable', 'required_if:period,custom', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'location_id' => ['nullable', 'integer'],
        ]);
        $period = $this->reports->resolvePeriod($validated['period'] ?? 'month', $validated['start_date'] ?? null, $validated['end_date'] ?? null);
        $locationId = isset($validated['location_id']) && InventoryLocation::query()->forDomain($slug)->whereKey($validated['location_id'])->exists()
            ? (int) $validated['location_id']
            : null;

        $query = OtherIncome::query()
            ->forDomain($slug)
            ->whereBetween('income_date', [$period['start']->toDateString(), $period['end']->toDateString()])
            ->when($locationId, fn ($q, $id) => $q->where('location_id', $id));

        return Inertia::render('Finance/OtherIncome', [
            'filters' => [
                ...$this->reports->describePeriod($period),
                'location_id' => $locationId,
            ],
            'items' => (clone $query)
                ->with(['location:id,name', 'user:id,name'])
                ->orderByDesc('income_date')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'total' => round((float) (clone $query)->sum('amount'), 2),
            'locations' => InventoryLocation::query()->forDomain($slug)->active()->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'defaultLocationId' => $request->user()->location_id,
            'domainName' => $domain->name,
        ]);
    }

    public function store(Request $request, Domain $domain): RedirectResponse
    {
        $validated = $this->validated($request, $domain);

        DB::transaction(function () use ($request, $domain, $validated) {
            $income = new OtherIncome([
                ...$validated,
                'domain' => $domain->name_slug,
                'user_id' => $request->user()->id,
            ]);
            $this->post($income);
            $income->save();
        });

        return back()->with('success', 'Income recorded.');
    }

    public function update(Request $request, Domain $domain, OtherIncome $otherIncome): RedirectResponse
    {
        abort_unless($otherIncome->domain === $domain->name_slug, 404);
        $validated = $this->validated($request, $domain);

        DB::transaction(function () use ($otherIncome, $validated) {
            // The old cash-in may sit on a now-closed shift; changing it would rewrite a counted drawer.
            $this->cashRegister->guardExisting($otherIncome, 'income_date');
            $otherIncome->fill($validated);
            $this->post($otherIncome);
            $otherIncome->save();
        });

        return back()->with('success', 'Income updated.');
    }

    public function destroy(Domain $domain, OtherIncome $otherIncome): RedirectResponse
    {
        abort_unless($otherIncome->domain === $domain->name_slug, 404);

        DB::transaction(fn () => $this->cashRegister->delete($otherIncome, 'income_date'));

        return back()->with('success', 'Income deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Domain $domain): array
    {
        $validated = $request->validate([
            'income_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'payment_method' => ['required', Rule::in(Expense::PAYMENT_METHODS)],
            // Put into the cash register: whose drawer it went into.
            'location_id' => [
                'nullable',
                'required_if:payment_method,cash_register',
                'integer',
                Rule::exists('inventory_locations', 'id')->where('domain', $domain->name_slug),
            ],
            'reference_no' => ['nullable', 'string', 'max:80'],
        ]);

        return [...$validated, 'location_id' => $validated['location_id'] ?? null, 'reference_no' => $validated['reference_no'] ?? null];
    }

    private function post(OtherIncome $income): void
    {
        $this->cashRegister->sync(
            $income,
            'other_income',
            'in',
            $income->income_date->toDateString(),
            'Other income: '.$income->description,
            'income_date',
        );
    }
}
