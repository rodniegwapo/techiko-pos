<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\RecurringExpenseService;
use App\Support\FinanceAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recurring expense templates. Same store rules as one-off expenses: admins any store or
 * business-wide, managers only their own store.
 */
class RecurringExpenseController extends Controller
{
    public function __construct(private RecurringExpenseService $recurring) {}

    public function store(Request $request, Domain $domain)
    {
        $user = $this->authorizeFinance($request);
        $data = $this->validated($request, $domain, $user);

        $template = new RecurringExpense($data + ['domain' => $domain->name_slug, 'user_id' => $user->id]);
        $template->next_run_date = $template->firstRunOnOrAfter($template->start_date);
        $template->save();

        // Book anything already due (e.g. a start date in the past) right away.
        $this->recurring->generateDue($domain->name_slug);

        return redirect()->back()->with('success', 'Recurring expense saved.');
    }

    public function update(Request $request, Domain $domain, RecurringExpense $recurring)
    {
        $user = $this->authorizeFinance($request);
        $this->ensureCanEdit($domain, $user, $recurring);

        if ($request->has('is_active') && count($request->except(['_method'])) === 1) {
            // Pause / resume. Resuming doesn't back-fill the runs missed while paused.
            $recurring->is_active = $request->boolean('is_active');
            if ($recurring->is_active && $recurring->next_run_date->lt(today())) {
                $recurring->next_run_date = $recurring->firstRunOnOrAfter(today());
            }
            $recurring->save();

            return redirect()->back()->with('success', $recurring->is_active ? 'Recurring expense resumed.' : 'Recurring expense paused.');
        }

        $recurring->fill($this->validated($request, $domain, $user));
        // A new schedule takes effect from the next run that hasn't been booked yet.
        $from = $recurring->start_date->max($recurring->getOriginal('next_run_date'));
        $recurring->next_run_date = $recurring->firstRunOnOrAfter($from);
        $recurring->save();

        $this->recurring->generateDue($domain->name_slug);

        return redirect()->back()->with('success', 'Recurring expense updated.');
    }

    public function destroy(Request $request, Domain $domain, RecurringExpense $recurring)
    {
        $user = $this->authorizeFinance($request);
        $this->ensureCanEdit($domain, $user, $recurring);

        // Expenses already booked stay; they just lose the link to the template.
        $recurring->delete();

        return redirect()->back()->with('success', 'Recurring expense deleted. Expenses already booked are kept.');
    }

    private function authorizeFinance(Request $request): User
    {
        $user = $request->user();
        abort_unless(FinanceAccess::canManage($user), 403);

        return $user;
    }

    private function ensureCanEdit(Domain $domain, User $user, RecurringExpense $recurring): void
    {
        abort_if($recurring->domain !== $domain->name_slug, 404);

        if (! FinanceAccess::canSeeAllStores($user)) {
            abort_unless(
                $recurring->location_id !== null && (int) $recurring->location_id === FinanceAccess::restrictedLocationId($user),
                $recurring->location_id === null ? 403 : 404
            );
        }
    }

    private function validated(Request $request, Domain $domain, User $user): array
    {
        $data = $request->validate([
            'expense_category_id' => [
                'required', 'integer',
                Rule::exists('expense_categories', 'id')->where('domain', $domain->name_slug)->where('is_active', true),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::in(\App\Models\Expense::PAYMENT_METHODS)],
            'location_id' => ['nullable', 'integer'],
            'frequency' => ['required', Rule::in(RecurringExpense::FREQUENCIES)],
            'day_of_month' => ['required_if:frequency,monthly', 'nullable', 'integer', 'between:1,31'],
            'day_of_week' => ['required_if:frequency,weekly', 'nullable', 'integer', 'between:0,6'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
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
            throw ValidationException::withMessages(['location_id' => 'Choose the store whose cash register pays this expense.']);
        }

        $data['location_id'] = $locationId;
        $data['start_date'] = Carbon::parse($data['start_date'])->toDateString();
        if ($data['frequency'] === 'monthly') {
            $data['day_of_week'] = null;
        } else {
            $data['day_of_month'] = null;
        }

        return $data;
    }
}
