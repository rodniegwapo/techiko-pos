<?php

namespace App\Http\Controllers\Domains\Finance;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Finance\FinancialAccount;
use App\Models\Finance\FinancialAccountBalance;
use App\Models\Finance\FixedAsset;
use App\Models\Finance\Loan;
use App\Models\Finance\LoanPayment;
use App\Models\Finance\OtherLiability;
use App\Models\Finance\OwnerInvestment;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\WalletCashMovement;
use App\Services\ExpenseService;
use App\Services\Finance\CashRegisterPosting;
use App\Support\Wallet\WalletCashBridgeExpected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The rest of what the business owns and owes, for the balance sheet and cash flow: bank and
 * e-wallet balances, loans and their repayments, equipment and other assets, owner investments.
 * Money through the cash register posts to the wallet ledger like expenses do.
 */
class BalanceItemsController extends Controller
{
    /** Interest paid on loans is booked under this expense category (type "other"). */
    private const INTEREST_CATEGORY = 'Loan interest';

    public function __construct(
        private readonly CashRegisterPosting $cashRegister,
        private readonly ExpenseService $expenses,
    ) {}

    public function index(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $today = today();

        $accounts = FinancialAccount::query()->forDomain($slug)->orderBy('name')
            ->with(['balances' => fn ($q) => $q->with('user:id,name')->limit(50), 'paymentCardType:id,name,kind'])
            ->get()
            ->map(function (FinancialAccount $a) use ($today) {
                $position = $a->positionOn($today->toDateString());

                return [
                    ...$a->only(['id', 'name', 'type', 'account_number', 'is_active', 'payment_card_type_id']),
                    'channel' => $a->paymentCardType?->name,
                    'balance' => $position['balance'],
                    'as_of' => $position['as_of'],
                    'received_since' => $position['received'],
                    'history' => $a->balances->take(10)->values(),
                ];
            });

        $otherLiabilities = OtherLiability::query()->forDomain($slug)->orderByRaw('settled_date IS NOT NULL')->orderByDesc('incurred_date')->get();

        $loans = Loan::query()->forDomain($slug)->with(['payments.location:id,name', 'location:id,name'])->orderByDesc('received_date')->get()
            ->map(fn (Loan $l) => [
                ...$l->toArray(),
                'balance' => $l->balanceOn(),
                'interest_paid' => round((float) $l->payments->sum('interest'), 2),
            ]);

        $assets = FixedAsset::query()->forDomain($slug)->with('location:id,name')->orderByDesc('purchase_date')->get()
            ->map(fn (FixedAsset $a) => [
                ...$a->toArray(),
                'book_value' => $a->bookValueOn($today),
                'monthly_depreciation' => round($a->dailyDepreciation() * 30.4375, 2),
            ]);

        $investments = OwnerInvestment::query()->forDomain($slug)->with('location:id,name')->orderByDesc('investment_date')->limit(100)->get();
        $withdrawn = (float) WalletCashMovement::query()->forDomain($slug)->where('kind', 'owner_draw')
            ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', '!=', WalletCashBridgeExpected::NOTE_ENDSHIFT_CASHOUT))
            ->sum('amount');

        return Inertia::render('Finance/AssetsAndLoans', [
            'accounts' => $accounts,
            'loans' => $loans,
            'assets' => $assets,
            'investments' => $investments,
            'otherLiabilities' => $otherLiabilities,
            'totals' => [
                'accounts' => round($accounts->sum(fn ($a) => (float) ($a['balance'] ?? 0)), 2),
                'loans' => round($loans->sum('balance'), 2),
                'assets' => round($assets->sum('book_value'), 2),
                'invested' => round((float) $investments->sum('amount'), 2),
                'withdrawn' => round($withdrawn, 2),
                'other_liabilities' => round((float) $otherLiabilities->whereNull('settled_date')->sum('amount'), 2),
            ],
            'tab' => in_array($request->query('tab'), ['accounts', 'loans', 'assets', 'investments', 'liabilities'], true) ? $request->query('tab') : 'accounts',
            'channels' => PaymentCardType::query()->forDomain($slug)->active()->with('location:id,name')->orderBy('name')->get(['id', 'name', 'kind', 'location_id'])
                ->map(fn (PaymentCardType $t) => ['id' => $t->id, 'name' => $t->name, 'kind' => $t->kind, 'location' => $t->location?->name]),
            'liabilityCategories' => OtherLiability::CATEGORIES,
            'locations' => InventoryLocation::query()->forDomain($slug)->active()->orderBy('name')->get(['id', 'name']),
            'paymentMethods' => Expense::PAYMENT_METHODS,
            'assetCategories' => FixedAsset::CATEGORIES,
            'defaultLocationId' => $request->user()->location_id,
            'domainName' => $domain->name,
        ]);
    }

    /* ---------- Bank and e-wallet accounts ---------- */

    public function storeAccount(Request $request, Domain $domain): RedirectResponse
    {
        $data = $this->validateAccount($request, $domain);

        DB::transaction(function () use ($request, $domain, $data) {
            $account = FinancialAccount::query()->create(['domain' => $domain->name_slug, ...collect($data)->except(['balance', 'as_of_date'])->all()]);
            if (isset($data['balance'])) {
                $account->balances()->create([
                    'as_of_date' => $data['as_of_date'] ?? today()->toDateString(),
                    'balance' => $data['balance'],
                    'note' => 'Starting balance',
                    'user_id' => $request->user()->id,
                ]);
            }
        });

        return back()->with('success', 'Account added.');
    }

    public function updateAccount(Request $request, Domain $domain, FinancialAccount $account): RedirectResponse
    {
        abort_unless($account->domain === $domain->name_slug, 404);
        $account->update(collect($this->validateAccount($request, $domain, $account))->except(['balance', 'as_of_date'])->all());

        return back()->with('success', 'Account updated.');
    }

    public function storeAccountBalance(Request $request, Domain $domain, FinancialAccount $account): RedirectResponse
    {
        abort_unless($account->domain === $domain->name_slug, 404);
        $data = $request->validate([
            'as_of_date' => ['required', 'date', 'before_or_equal:today'],
            'balance' => ['required', 'numeric', 'min:-99999999.99', 'max:999999999.99'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $account->balances()->create([...$data, 'user_id' => $request->user()->id]);

        return back()->with('success', 'Balance recorded.');
    }

    public function destroyAccountBalance(Domain $domain, FinancialAccountBalance $balance): RedirectResponse
    {
        abort_unless($balance->account?->domain === $domain->name_slug, 404);
        $balance->delete();

        return back()->with('success', 'Balance deleted.');
    }

    /* ---------- Loans ---------- */

    public function storeLoan(Request $request, Domain $domain): RedirectResponse
    {
        $data = $this->validateLoan($request, $domain);

        DB::transaction(function () use ($request, $domain, $data) {
            $loan = new Loan([...$data, 'domain' => $domain->name_slug, 'user_id' => $request->user()->id]);
            $this->postLoan($loan);
            $loan->save();
        });

        return back()->with('success', 'Loan recorded.');
    }

    public function updateLoan(Request $request, Domain $domain, Loan $loan): RedirectResponse
    {
        abort_unless($loan->domain === $domain->name_slug, 404);
        $data = $this->validateLoan($request, $domain);

        $repaid = (float) $loan->payments()->sum('principal');
        if ((float) $data['principal'] < $repaid) {
            throw ValidationException::withMessages(['principal' => 'The loan can\'t be less than the ₱'.number_format($repaid, 2).' already repaid.']);
        }

        DB::transaction(function () use ($loan, $data) {
            $this->cashRegister->guardExisting($loan, 'received_date');
            $loan->fill($data);
            $this->postLoan($loan);
            $loan->save();
        });

        return back()->with('success', 'Loan updated.');
    }

    public function destroyLoan(Domain $domain, Loan $loan): RedirectResponse
    {
        abort_unless($loan->domain === $domain->name_slug, 404);
        if ($loan->payments()->exists()) {
            throw ValidationException::withMessages(['loan' => 'This loan has repayments. Delete them first.']);
        }

        DB::transaction(fn () => $this->cashRegister->delete($loan, 'received_date'));

        return back()->with('success', 'Loan deleted.');
    }

    public function storeLoanPayment(Request $request, Domain $domain, Loan $loan): RedirectResponse
    {
        abort_unless($loan->domain === $domain->name_slug, 404);
        $data = $request->validate([
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'principal' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'interest' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            ...$this->methodRules($domain),
            'reference_no' => ['nullable', 'string', 'max:80'],
        ]);
        $interest = round((float) ($data['interest'] ?? 0), 2);
        if ((float) $data['principal'] + $interest <= 0) {
            throw ValidationException::withMessages(['principal' => 'Enter the principal and/or interest paid.']);
        }

        DB::transaction(function () use ($request, $domain, $loan, $data, $interest) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);
            if (round((float) $data['principal'], 2) > $loan->balanceOn()) {
                throw ValidationException::withMessages(['principal' => 'Only ₱'.number_format($loan->balanceOn(), 2).' of this loan is still owed.']);
            }

            $payment = new LoanPayment([
                'domain' => $domain->name_slug,
                'loan_id' => $loan->id,
                'payment_date' => $data['payment_date'],
                'principal' => $data['principal'],
                'interest' => $interest,
                'payment_method' => $data['payment_method'],
                'location_id' => $data['location_id'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            // The principal part comes out of the drawer here; the interest part is an expense,
            // which posts its own drawer line.
            if ((float) $payment->principal > 0) {
                $this->cashRegister->sync($payment, 'loan_payment', 'out', $payment->payment_date->toDateString(),
                    'Loan repayment: '.$loan->lender, 'payment_date', (float) $payment->principal);
            }

            if ($interest > 0) {
                $payment->expense_id = $this->expenses->create([
                    'domain' => $domain->name_slug,
                    'location_id' => $payment->location_id,
                    'expense_category_id' => $this->interestCategory($domain)->id,
                    'amount' => $interest,
                    'expense_date' => $payment->payment_date->toDateString(),
                    'description' => 'Interest on loan from '.$loan->lender,
                    'payee' => $loan->lender,
                    'payment_method' => $payment->payment_method,
                    'reference_no' => $payment->reference_no,
                    'user_id' => $request->user()->id,
                ])->id;
            }

            $payment->save();
        });

        return back()->with('success', 'Repayment recorded.');
    }

    public function destroyLoanPayment(Domain $domain, LoanPayment $payment): RedirectResponse
    {
        abort_unless($payment->domain === $domain->name_slug, 404);

        DB::transaction(function () use ($payment) {
            $expense = $payment->expense;
            $this->cashRegister->delete($payment, 'payment_date');
            if ($expense) {
                $this->expenses->delete($expense);
            }
        });

        return back()->with('success', 'Repayment deleted.');
    }

    /* ---------- Equipment and other assets ---------- */

    public function storeAsset(Request $request, Domain $domain): RedirectResponse
    {
        $data = $this->validateAsset($request, $domain);

        DB::transaction(function () use ($request, $domain, $data) {
            $asset = new FixedAsset([...$data, 'domain' => $domain->name_slug, 'user_id' => $request->user()->id]);
            $this->postAsset($asset);
            $asset->save();
        });

        return back()->with('success', 'Asset recorded.');
    }

    public function updateAsset(Request $request, Domain $domain, FixedAsset $asset): RedirectResponse
    {
        abort_unless($asset->domain === $domain->name_slug, 404);
        $data = $this->validateAsset($request, $domain);

        DB::transaction(function () use ($asset, $data) {
            $this->cashRegister->guardExisting($asset, 'purchase_date');
            $asset->fill($data);
            $this->postAsset($asset);
            $asset->save();
        });

        return back()->with('success', 'Asset updated.');
    }

    public function destroyAsset(Domain $domain, FixedAsset $asset): RedirectResponse
    {
        abort_unless($asset->domain === $domain->name_slug, 404);
        DB::transaction(fn () => $this->cashRegister->delete($asset, 'purchase_date'));

        return back()->with('success', 'Asset deleted.');
    }

    /* ---------- Owner investments ---------- */

    public function storeInvestment(Request $request, Domain $domain): RedirectResponse
    {
        $data = $this->validateInvestment($request, $domain);

        DB::transaction(function () use ($request, $domain, $data) {
            $investment = new OwnerInvestment([...$data, 'domain' => $domain->name_slug, 'user_id' => $request->user()->id]);
            $this->postInvestment($investment);
            $investment->save();
        });

        return back()->with('success', 'Investment recorded.');
    }

    public function updateInvestment(Request $request, Domain $domain, OwnerInvestment $investment): RedirectResponse
    {
        abort_unless($investment->domain === $domain->name_slug, 404);
        $data = $this->validateInvestment($request, $domain);

        DB::transaction(function () use ($investment, $data) {
            $this->cashRegister->guardExisting($investment, 'investment_date');
            $investment->fill($data);
            $this->postInvestment($investment);
            $investment->save();
        });

        return back()->with('success', 'Investment updated.');
    }

    public function destroyInvestment(Domain $domain, OwnerInvestment $investment): RedirectResponse
    {
        abort_unless($investment->domain === $domain->name_slug, 404);
        DB::transaction(fn () => $this->cashRegister->delete($investment, 'investment_date'));

        return back()->with('success', 'Investment deleted.');
    }

    /* ---------- Other liabilities ---------- */

    public function storeLiability(Request $request, Domain $domain): RedirectResponse
    {
        OtherLiability::query()->create([
            ...$this->validateLiability($request),
            'domain' => $domain->name_slug,
            'user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Liability recorded.');
    }

    public function updateLiability(Request $request, Domain $domain, OtherLiability $liability): RedirectResponse
    {
        abort_unless($liability->domain === $domain->name_slug, 404);
        $liability->update($this->validateLiability($request));

        return back()->with('success', 'Liability updated.');
    }

    public function destroyLiability(Domain $domain, OtherLiability $liability): RedirectResponse
    {
        abort_unless($liability->domain === $domain->name_slug, 404);
        $liability->delete();

        return back()->with('success', 'Liability deleted.');
    }

    /* ---------- Helpers ---------- */

    /**
     * Owed from the day it arose until it is settled. Paying it is recorded as what it was for
     * (an expense, a refund of the deposit…); here it only counts what is still owed.
     */
    private function validateLiability(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(OtherLiability::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'incurred_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:incurred_date'],
            'settled_date' => ['nullable', 'date', 'after_or_equal:incurred_date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return [...$data, 'due_date' => $data['due_date'] ?? null, 'settled_date' => $data['settled_date'] ?? null];
    }

    /** Paid with / received into: cash register (a store's drawer), bank, e-wallet, card or other. */
    private function methodRules(Domain $domain): array
    {
        return [
            'payment_method' => ['required', Rule::in(Expense::PAYMENT_METHODS)],
            'location_id' => [
                'nullable',
                'required_if:payment_method,cash_register',
                'integer',
                Rule::exists('inventory_locations', 'id')->where('domain', $domain->name_slug),
            ],
        ];
    }

    private function validateAccount(Request $request, Domain $domain, ?FinancialAccount $account = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('financial_accounts', 'name')->where('domain', $domain->name_slug)->ignore($account?->id)],
            'type' => ['required', Rule::in(FinancialAccount::TYPES)],
            'account_number' => ['nullable', 'string', 'max:60'],
            // Sales paid through this channel are added to the account's balance.
            'payment_card_type_id' => ['nullable', 'integer', Rule::exists('payment_card_types', 'id')->where('domain', $domain->name_slug)],
            'is_active' => ['boolean'],
            'balance' => ['nullable', 'numeric', 'min:-99999999.99', 'max:999999999.99'],
            'as_of_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
    }

    private function validateLoan(Request $request, Domain $domain): array
    {
        $data = $request->validate([
            'lender' => ['required', 'string', 'max:120'],
            'received_date' => ['required', 'date', 'before_or_equal:today'],
            'principal' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'due_date' => ['nullable', 'date', 'after_or_equal:received_date'],
            ...$this->methodRules($domain),
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return [...$data, 'location_id' => $data['location_id'] ?? null];
    }

    private function validateAsset(Request $request, Domain $domain): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(FixedAsset::CATEGORIES)],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'cost' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            ...$this->methodRules($domain),
            'disposed_date' => ['nullable', 'date', 'after_or_equal:purchase_date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return [...$data, 'location_id' => $data['location_id'] ?? null];
    }

    private function validateInvestment(Request $request, Domain $domain): array
    {
        $data = $request->validate([
            'investment_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            ...$this->methodRules($domain),
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return [...$data, 'location_id' => $data['location_id'] ?? null];
    }

    private function postLoan(Loan $loan): void
    {
        $this->cashRegister->sync($loan, 'loan_received', 'in', $loan->received_date->toDateString(),
            'Loan received: '.$loan->lender, 'received_date', (float) $loan->principal);
    }

    private function postAsset(FixedAsset $asset): void
    {
        $this->cashRegister->sync($asset, 'asset_purchase', 'out', $asset->purchase_date->toDateString(),
            'Bought: '.$asset->name, 'purchase_date', (float) $asset->cost);
    }

    private function postInvestment(OwnerInvestment $investment): void
    {
        $this->cashRegister->sync($investment, 'owner_investment', 'in', $investment->investment_date->toDateString(),
            'Owner investment'.($investment->notes ? ': '.$investment->notes : ''), 'investment_date');
    }

    private function interestCategory(Domain $domain): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrCreate(
            ['domain' => $domain->name_slug, 'name' => self::INTEREST_CATEGORY],
            ['type' => 'other', 'is_active' => true, 'is_default' => false],
        );
    }
}
