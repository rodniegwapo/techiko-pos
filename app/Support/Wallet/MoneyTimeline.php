<?php

namespace App\Support\Wallet;

use App\Models\Domain;
use App\Models\Finance\FinancialAccount;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every peso in and out for one store, read straight from the records that own it: the store's
 * wallet ledger (drawer and payment channels), plus money that never touches the drawer: bank,
 * e-wallet, card or other payments of expenses, supplier bills, other income, loans, equipment and
 * owner investments; card and bank sales; customer credit payments. Read-only; nothing is copied
 * into the ledger, so drawer counts are unaffected.
 *
 * A finance record paid from the cash register is left out of its own part: its ledger line is
 * already in, so it is counted once.
 */
class MoneyTimeline
{
    /** Where filter values besides drawer, channel:{id} and account:{id}. */
    public const METHOD_WHERES = ['bank', 'ewallet', 'card', 'cash', 'other'];

    /**
     * @return array<string, mixed>
     */
    public static function build(Request $request, Domain $domain, InventoryLocation $location): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'direction' => ['nullable', 'in:in,out'],
            'where' => ['nullable', 'string', 'max:40', 'regex:/^(drawer|channel:\d+|account:\d+|'.implode('|', self::METHOD_WHERES).')$/'],
            'business_wide' => ['nullable', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $from = isset($validated['date_from']) ? Carbon::parse($validated['date_from'])->toDateString() : now()->startOfMonth()->toDateString();
        $to = isset($validated['date_to']) ? Carbon::parse($validated['date_to'])->toDateString() : now()->toDateString();
        $businessWide = $request->has('business_wide') ? $request->boolean('business_wide') : true;
        $where = $validated['where'] ?? null;
        $direction = $validated['direction'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 20);

        $rows = DB::query()
            ->fromSub(self::union($domain->name_slug, (int) $location->id), 't')
            ->whereBetween('date', [$from, $to])
            ->where(fn ($q) => $q->where('location_id', $location->id)
                ->when($businessWide, fn ($q) => $q->orWhereNull('location_id')))
            ->when($direction, fn ($q, $d) => $q->where('direction', $d))
            ->when($where, fn ($q, $w) => self::applyWhere($q, $w));

        $totals = (clone $rows)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) AS money_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) AS money_out")
            ->first();
        $in = round((float) $totals->money_in, 2);
        $out = round((float) $totals->money_out, 2);

        return [
            'rows' => $rows
                ->orderByDesc('date')
                ->orderByDesc('created_at')
                ->orderByDesc('record_id')
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn ($r) => [
                    'key' => $r->source.'-'.$r->record_id,
                    'date' => $r->date,
                    'direction' => $r->direction,
                    'amount' => round((float) $r->amount, 2),
                    'source' => $r->source,
                    'kind' => $r->kind,
                    'label' => $r->label,
                    'where_type' => $r->where_type,
                    'where_id' => $r->where_id !== null ? (int) $r->where_id : null,
                    'where_name' => $r->where_name,
                    'location_id' => $r->location_id !== null ? (int) $r->location_id : null,
                    'record_id' => (int) $r->record_id,
                ]),
            'totals' => ['in' => $in, 'out' => $out, 'net' => round($in - $out, 2)],
            'filters' => [
                'date_from' => $from,
                'date_to' => $to,
                'direction' => $direction,
                'where' => $where,
                'business_wide' => $businessWide,
            ],
            'whereOptions' => self::whereOptions($domain, $location),
        ];
    }

    private static function applyWhere(Builder $q, string $where): void
    {
        if ($where === 'drawer') {
            $q->where('where_type', 'drawer');
        } elseif (str_starts_with($where, 'channel:')) {
            $q->where('where_type', 'channel')->where('where_id', (int) substr($where, 8));
        } elseif (str_starts_with($where, 'account:')) {
            $q->where('where_type', 'account')->where('where_id', (int) substr($where, 8));
        } else {
            $q->where('where_type', $where);
        }
    }

    /** @return list<array{value: string, label: string}> */
    private static function whereOptions(Domain $domain, InventoryLocation $location): array
    {
        $channels = PaymentCardType::query()->forDomainLocation($domain->name_slug, $location->id)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $accounts = FinancialAccount::query()->forDomain($domain->name_slug)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name']);

        return [
            ['value' => 'drawer', 'label' => 'Cash drawer'],
            ...$channels->map(fn ($c) => ['value' => 'channel:'.$c->id, 'label' => $c->name])->all(),
            ...$accounts->map(fn ($a) => ['value' => 'account:'.$a->id, 'label' => $a->name])->all(),
            ['value' => 'bank', 'label' => 'Bank (no account picked)'],
            ['value' => 'ewallet', 'label' => 'E-wallet (no account picked)'],
            ['value' => 'card', 'label' => 'Card'],
            ['value' => 'cash', 'label' => 'Cash (not in the drawer ledger)'],
            ['value' => 'other', 'label' => 'Other'],
        ];
    }

    /** All sources as one list with the same columns. */
    private static function union(string $domain, int $locationId): Builder
    {
        // Finance records: the account they were paid from when known, else their method.
        $whereType = fn (string $t, bool $hasAccount) => $hasAccount
            ? "CASE WHEN {$t}.financial_account_id IS NOT NULL THEN 'account' ELSE {$t}.payment_method END"
            : "{$t}.payment_method";

        $ledger = DB::table('wallet_cash_movements as m')
            ->leftJoin('payment_card_types as p', 'p.id', '=', 'm.payment_card_type_id')
            ->where('m.domain', $domain)
            ->where('m.location_id', $locationId)
            // Same rule as the running balance: skip book-only cash-control lines.
            ->where(fn ($q) => $q->whereNull('m.notes')
                ->orWhere('m.notes', WalletCashBridgeExpected::NOTE_ENDSHIFT_CASHOUT)
                ->orWhere('m.notes', 'not like', 'AUTO_CC_%'))
            ->selectRaw("m.movement_date AS date, m.direction, m.amount, 'ledger' AS source, m.kind, m.notes AS label,
                CASE WHEN m.payment_card_type_id IS NULL THEN 'drawer' ELSE 'channel' END AS where_type,
                m.payment_card_type_id AS where_id, p.name AS where_name, m.location_id, m.id AS record_id, m.created_at");

        $expenses = DB::table('expenses as e')
            ->leftJoin('financial_accounts as fa', 'fa.id', '=', 'e.financial_account_id')
            ->where('e.domain', $domain)
            ->whereNull('e.deleted_at')
            ->where('e.payment_method', '!=', 'cash_register')
            ->selectRaw("e.expense_date AS date, 'out' AS direction, e.amount, 'expense' AS source, 'expense' AS kind, e.description AS label,
                {$whereType('e', true)} AS where_type, e.financial_account_id AS where_id, fa.name AS where_name, e.location_id, e.id AS record_id, e.created_at");

        $supplierPayments = DB::table('supplier_payments as sp')
            ->join('supplier_bills as b', 'b.id', '=', 'sp.supplier_bill_id')
            ->join('suppliers as s', 's.id', '=', 'b.supplier_id')
            ->leftJoin('financial_accounts as fa', 'fa.id', '=', 'sp.financial_account_id')
            ->where('sp.domain', $domain)
            ->where('sp.payment_method', '!=', 'cash_register')
            ->selectRaw("sp.payment_date AS date, 'out' AS direction, sp.amount, 'supplier_payment' AS source, 'supplier_payment' AS kind,
                CONCAT(s.name, CASE WHEN b.bill_number IS NULL THEN '' ELSE CONCAT(' (bill ', b.bill_number, ')') END) AS label,
                {$whereType('sp', true)} AS where_type, sp.financial_account_id AS where_id, fa.name AS where_name, sp.location_id, sp.id AS record_id, sp.created_at");

        $otherIncome = DB::table('other_incomes as oi')
            ->where('oi.domain', $domain)
            ->where('oi.payment_method', '!=', 'cash_register')
            ->selectRaw("oi.income_date AS date, 'in' AS direction, oi.amount, 'other_income' AS source, 'other_income' AS kind, oi.description AS label,
                {$whereType('oi', false)} AS where_type, NULL AS where_id, NULL AS where_name, oi.location_id, oi.id AS record_id, oi.created_at");

        $loans = DB::table('loans as l')
            ->where('l.domain', $domain)
            ->where('l.payment_method', '!=', 'cash_register')
            ->selectRaw("l.received_date AS date, 'in' AS direction, l.principal AS amount, 'loan' AS source, 'loan_received' AS kind, l.lender AS label,
                {$whereType('l', false)} AS where_type, NULL AS where_id, NULL AS where_name, l.location_id, l.id AS record_id, l.created_at");

        // Principal only: the interest part is booked as an expense of its own.
        $loanPayments = DB::table('loan_payments as lp')
            ->join('loans as l', 'l.id', '=', 'lp.loan_id')
            ->where('lp.domain', $domain)
            ->where('lp.payment_method', '!=', 'cash_register')
            ->where('lp.principal', '>', 0)
            ->selectRaw("lp.payment_date AS date, 'out' AS direction, lp.principal AS amount, 'loan_payment' AS source, 'loan_payment' AS kind, l.lender AS label,
                {$whereType('lp', false)} AS where_type, NULL AS where_id, NULL AS where_name, lp.location_id, lp.id AS record_id, lp.created_at");

        $assets = DB::table('fixed_assets as fx')
            ->where('fx.domain', $domain)
            ->where('fx.payment_method', '!=', 'cash_register')
            ->selectRaw("fx.purchase_date AS date, 'out' AS direction, fx.cost AS amount, 'asset' AS source, 'asset_purchase' AS kind, fx.name AS label,
                {$whereType('fx', false)} AS where_type, NULL AS where_id, NULL AS where_name, fx.location_id, fx.id AS record_id, fx.created_at");

        $investments = DB::table('owner_investments as oinv')
            ->where('oinv.domain', $domain)
            ->where('oinv.payment_method', '!=', 'cash_register')
            ->selectRaw("oinv.investment_date AS date, 'in' AS direction, oinv.amount, 'investment' AS source, 'owner_investment' AS kind, oinv.notes AS label,
                {$whereType('oinv', false)} AS where_type, NULL AS where_id, NULL AS where_name, oinv.location_id, oinv.id AS record_id, oinv.created_at");

        // Card and bank sales never post to the ledger; cash and e-wallet ones already did.
        $channelType = "CASE WHEN %s IS NOT NULL THEN 'channel' ELSE %s END";
        $sales = DB::table('sales as sa')
            ->leftJoin('payment_card_types as p', 'p.id', '=', 'sa.payment_card_type_id')
            ->where('sa.domain', $domain)
            ->where('sa.location_id', $locationId)
            ->where('sa.payment_status', 'paid')
            ->whereIn('sa.payment_method', ['card', 'bank'])
            ->selectRaw("DATE(sa.transaction_date) AS date, 'in' AS direction, sa.grand_total AS amount, 'sale' AS source, 'sale_payment' AS kind,
                CONCAT('Sale #', sa.invoice_number) AS label, ".sprintf($channelType, 'sa.payment_card_type_id', 'sa.payment_method')." AS where_type,
                sa.payment_card_type_id AS where_id, p.name AS where_name, sa.location_id, sa.id AS record_id, sa.created_at");

        $splitParts = DB::table('sale_payments as pay')
            ->join('sales as sa', 'sa.id', '=', 'pay.sale_id')
            ->leftJoin('payment_card_types as p', 'p.id', '=', 'pay.payment_card_type_id')
            ->where('sa.domain', $domain)
            ->where('sa.location_id', $locationId)
            ->where('sa.payment_status', 'paid')
            ->where('sa.payment_method', 'split')
            ->whereIn('pay.method', ['card', 'bank'])
            ->selectRaw("DATE(sa.transaction_date) AS date, 'in' AS direction, pay.amount, 'sale_part' AS source, 'sale_payment' AS kind,
                CONCAT('Sale #', sa.invoice_number, ' (', pay.method, ')') AS label, ".sprintf($channelType, 'pay.payment_card_type_id', 'pay.method')." AS where_type,
                pay.payment_card_type_id AS where_id, p.name AS where_name, sa.location_id, pay.id AS record_id, pay.created_at");

        // Cash ones taken at a store are in the ledger already. Older payments have no store: business-wide.
        $creditPayments = DB::table('credit_transactions as ct')
            ->join('customers as c', 'c.id', '=', 'ct.customer_id')
            ->where('ct.domain', $domain)
            ->where('ct.transaction_type', 'payment')
            ->whereNull('ct.wallet_cash_movement_id')
            ->selectRaw("DATE(ct.created_at) AS date, 'in' AS direction, ABS(ct.amount) AS amount, 'credit_payment' AS source, 'credit_payment' AS kind,
                c.name AS label,
                CASE WHEN ct.payment_method = 'e-wallet' THEN 'ewallet' WHEN ct.payment_method IN ('cash', 'card', 'bank') THEN ct.payment_method ELSE 'other' END AS where_type,
                NULL AS where_id, NULL AS where_name, ct.location_id, ct.id AS record_id, ct.created_at");

        return $ledger
            ->unionAll($expenses)
            ->unionAll($supplierPayments)
            ->unionAll($otherIncome)
            ->unionAll($loans)
            ->unionAll($loanPayments)
            ->unionAll($assets)
            ->unionAll($investments)
            ->unionAll($sales)
            ->unionAll($splitParts)
            ->unionAll($creditPayments);
    }
}
