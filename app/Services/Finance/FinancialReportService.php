<?php

namespace App\Services\Finance;

use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Finance\SupplierBill;
use App\Models\Finance\SupplierPayment;
use App\Models\InventoryLocation;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use App\Support\Wallet\WalletCashDailyExpected;
use App\Services\Reports\ProfitAndLossService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every figure on the Finance pages comes from here, computed straight from the books.
 * The AI assistant only puts these figures into words; it never calculates anything itself.
 *
 * Covers what the POS records: sales, cost of goods, inventory, customer credit, the cash drawer,
 * expenses, other income and supplier bills. Bank accounts, loans, equipment and owner
 * investments are not recorded yet, so the balance sheet leaves them out.
 */
class FinancialReportService
{
    public const PERIODS = ['today', 'week', 'month', 'year', 'custom'];

    /** Overdue credit is grouped by how long it has been late. */
    private const AGING_BUCKETS = [
        '1_30' => [1, 30],
        '31_60' => [31, 60],
        '61_90' => [61, 90],
        'over_90' => [91, null],
    ];

    public function __construct(private readonly ProfitAndLossService $pnl) {}

    /**
     * The period being looked at and the one it is compared with. Presets compare like with like
     * (this month so far against the same days last month); a custom range is compared with the
     * same number of days just before it.
     *
     * @return array{period: string, start: Carbon, end: Carbon, previous_start: Carbon, previous_end: Carbon}
     */
    public function resolvePeriod(string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        $today = now();

        [$start, $end] = match ($period) {
            'today' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            'week' => [$today->copy()->startOfWeek(), $today->copy()->endOfDay()],
            'year' => [$today->copy()->startOfYear(), $today->copy()->endOfDay()],
            'custom' => [
                Carbon::parse($startDate ?? $today->toDateString())->startOfDay(),
                Carbon::parse($endDate ?? $startDate ?? $today->toDateString())->endOfDay(),
            ],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
        };

        [$previousStart, $previousEnd] = match ($period) {
            'today' => [$start->copy()->subDay(), $end->copy()->subDay()],
            'week' => [$start->copy()->subWeek(), $end->copy()->subWeek()],
            'year' => [$start->copy()->subYearNoOverflow(), $end->copy()->subYearNoOverflow()],
            'custom' => [
                $start->copy()->subDays((int) round($start->diffInDays($end->copy()->startOfDay())) + 1),
                $start->copy()->subDay()->endOfDay(),
            ],
            default => [$start->copy()->subMonthNoOverflow(), $end->copy()->subMonthNoOverflow()],
        };

        return [
            'period' => in_array($period, self::PERIODS, true) ? $period : 'month',
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
        ];
    }

    /**
     * Sales and gross profit for a period: what customers paid, less the VAT collected for the
     * government, less what the goods sold cost.
     *
     * @return array<string, float|int>
     */
    public function profitAndLoss(string $domain, ?int $locationId, Carbon $start, Carbon $end): array
    {
        $sales = $this->paidSales($domain, $locationId, $start, $end);

        $totals = (clone $sales)
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total_paid')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as vat')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discounts')
            ->first();

        // Voided lines are soft-deleted, so they drop out of cost of goods sold on their own.
        $soldItems = SaleItem::query()->whereIn('sale_id', (clone $sales)->select('sales.id'));
        $cogs = (float) (clone $soldItems)->whereNotNull('unit_cost')->sum(DB::raw('unit_cost * quantity'));

        $totalPaid = (float) $totals->total_paid;
        $vat = (float) $totals->vat;
        $revenue = $totalPaid - $vat;
        $grossProfit = $revenue - $cogs;
        $salesCount = (int) $totals->sales_count;

        return [
            'sales_count' => $salesCount,
            'total_paid' => round($totalPaid, 2),
            'vat' => round($vat, 2),
            'discounts' => round((float) $totals->discounts, 2),
            'revenue' => round($revenue, 2),
            'cogs' => round($cogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'gross_margin_pct' => $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : 0.0,
            'average_sale' => $salesCount > 0 ? round($totalPaid / $salesCount, 2) : 0.0,
            'items_missing_cost' => (clone $soldItems)->whereNull('unit_cost')->count(),
        ];
    }

    /**
     * How customers paid during the period. Sales put on credit are not money in yet; what
     * customers paid back on their credit is (organization-wide, credit isn't kept per location).
     *
     * @return array<string, float>
     */
    public function moneyIn(string $domain, ?int $locationId, Carbon $start, Carbon $end): array
    {
        $sales = $this->paidSales($domain, $locationId, $start, $end);

        $byMethod = [];
        foreach (['cash', 'card', 'e-wallet', 'bank'] as $method) {
            $byMethod[$method] = SalePayment::totalFor($sales, $method);
        }
        $collections = $locationId ? 0.0 : $this->creditTotal($domain, 'payment', $start, $end);

        return [
            ...$byMethod,
            'credit_collections' => $collections,
            'sold_on_credit' => SalePayment::totalFor($sales, 'credit'),
            'total_received' => round(array_sum($byMethod) + $collections, 2),
        ];
    }

    /** What the stock on hand cost, at weighted average cost. */
    public function inventoryValue(string $domain, ?int $locationId): float
    {
        return round((float) ProductInventory::query()
            ->whereIn('location_id', $this->locationIds($domain, $locationId))
            ->where('quantity_on_hand', '>', 0)
            ->sum('total_value'), 2);
    }

    /**
     * Cash the drawers should hold right now: today's opening cash plus cash sales and money
     * put in, less money taken out. Only counts locations that recorded an opening today.
     *
     * @return array{amount: float, locations_counted: int}
     */
    public function cashInDrawer(string $domain, ?int $locationId): array
    {
        $today = today()->toDateString();
        $amount = 0.0;
        $counted = 0;

        foreach ($this->locationIds($domain, $locationId) as $id) {
            $recon = WalletCashReconciliation::query()
                ->forWalletContext($domain, $id)
                ->whereDate('business_date', $today)
                ->first();
            if ($recon === null) {
                continue;
            }

            $amount += WalletCashDailyExpected::compute($domain, $id, $today, $recon)['expected_cash'];
            $counted++;
        }

        return ['amount' => round($amount, 2), 'locations_counted' => $counted];
    }

    /**
     * Who owes the business money, how much of it is late and for how long.
     * Credit is organization-wide, so this ignores the location filter.
     *
     * @return array<string, mixed>
     */
    public function receivables(string $domain, Carbon $start, Carbon $end, Carbon $previousStart, Carbon $previousEnd): array
    {
        $outstanding = round((float) Customer::query()->forDomain($domain)->where('credit_balance', '>', 0)->sum('credit_balance'), 2);
        $customersOwing = Customer::query()->forDomain($domain)->where('credit_balance', '>', 0)->count();

        $overdueCharges = CreditTransaction::query()
            ->forDomain($domain)
            ->overdue()
            ->with(['installments', 'customer:id,name'])
            ->get();

        $aging = array_fill_keys(array_keys(self::AGING_BUCKETS), 0.0);
        $overdueByCustomer = [];
        foreach ($overdueCharges as $charge) {
            $amount = $charge->overdueAmount();
            $days = (int) $charge->getDaysOverdue();

            foreach (self::AGING_BUCKETS as $bucket => [$from, $to]) {
                if ($days >= $from && ($to === null || $days <= $to)) {
                    $aging[$bucket] += $amount;
                    break;
                }
            }

            $customerId = (int) $charge->customer_id;
            $overdueByCustomer[$customerId] ??= [
                'customer_id' => $customerId,
                'name' => $charge->customer?->name ?? 'Unknown customer',
                'overdue' => 0.0,
                'days_overdue' => 0,
            ];
            $overdueByCustomer[$customerId]['overdue'] += $amount;
            $overdueByCustomer[$customerId]['days_overdue'] = max($overdueByCustomer[$customerId]['days_overdue'], $days);
        }
        $overdueTotal = round(array_sum($aging), 2);

        $overdueCustomers = collect($overdueByCustomer)
            ->map(fn ($row) => [...$row, 'overdue' => round($row['overdue'], 2)])
            ->sortByDesc('overdue')
            ->values()
            ->take(10)
            ->all();

        $largest = Customer::query()
            ->forDomain($domain)
            ->where('credit_balance', '>', 0)
            ->orderByDesc('credit_balance')
            ->limit(10)
            ->get(['id', 'name', 'credit_balance', 'credit_limit'])
            ->map(fn (Customer $c) => [
                'customer_id' => $c->id,
                'name' => $c->name,
                'balance' => round((float) $c->credit_balance, 2),
                'credit_limit' => round((float) $c->credit_limit, 2),
                'overdue' => round($overdueByCustomer[$c->id]['overdue'] ?? 0, 2),
            ])
            ->all();

        $topThree = array_sum(array_column(array_slice($largest, 0, 3), 'balance'));

        $dueSoon = CreditTransaction::query()
            ->forDomain($domain)
            ->credit()
            ->whereNull('paid_at')
            ->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()])
            ->get()
            ->sum(fn (CreditTransaction $t) => $t->remaining);

        return [
            'outstanding' => $outstanding,
            'overdue' => $overdueTotal,
            'not_yet_due' => round(max(0, $outstanding - $overdueTotal), 2),
            'overdue_pct' => $outstanding > 0 ? round($overdueTotal / $outstanding * 100, 1) : 0.0,
            'due_within_7_days' => round((float) $dueSoon, 2),
            'customers_owing' => $customersOwing,
            'top_three_share_pct' => $outstanding > 0 ? round($topThree / $outstanding * 100, 1) : 0.0,
            'aging' => array_map(fn ($v) => round($v, 2), $aging),
            'largest_balances' => $largest,
            'overdue_customers' => $overdueCustomers,
            'period' => [
                'new_credit' => $this->creditTotal($domain, 'credit', $start, $end),
                'collected' => $this->creditTotal($domain, 'payment', $start, $end),
            ],
            'previous_period' => [
                'new_credit' => $this->creditTotal($domain, 'credit', $previousStart, $previousEnd),
                'collected' => $this->creditTotal($domain, 'payment', $previousStart, $previousEnd),
            ],
        ];
    }

    /**
     * Best sellers and the products that earn the least on each sale. Line totals may include VAT,
     * so margins here are a guide, not exact.
     *
     * @return array{best_sellers: array<int, array<string, mixed>>, low_margin: array<int, array<string, mixed>>}
     */
    public function productMargins(string $domain, ?int $locationId, Carbon $start, Carbon $end, int $limit = 5): array
    {
        $rows = SaleItem::query()
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereIn('sale_items.sale_id', $this->paidSales($domain, $locationId, $start, $end)->select('sales.id'))
            ->whereNotNull('sale_items.unit_cost')
            ->groupBy('sale_items.product_id', 'products.name')
            ->select('sale_items.product_id', 'products.name')
            ->selectRaw('SUM(sale_items.quantity) as quantity')
            ->selectRaw('SUM(sale_items.subtotal) as sales')
            ->selectRaw('SUM(sale_items.unit_cost * sale_items.quantity) as cost')
            ->get()
            ->map(function ($row) {
                $sales = (float) $row->sales;
                $cost = (float) $row->cost;

                return [
                    'product_id' => (int) $row->product_id,
                    'name' => $row->name,
                    'quantity' => (float) $row->quantity,
                    'sales' => round($sales, 2),
                    'profit' => round($sales - $cost, 2),
                    'margin_pct' => $sales > 0 ? round(($sales - $cost) / $sales * 100, 1) : 0.0,
                ];
            });

        return [
            'best_sellers' => $rows->sortByDesc('sales')->take($limit)->values()->all(),
            'low_margin' => $rows->where('sales', '>', 0)->sortBy('margin_pct')->take($limit)->values()->all(),
        ];
    }

    /**
     * Revenue and gross profit for each of the last few months, oldest first.
     *
     * @return array<int, array{month: string, label: string, revenue: float, gross_profit: float}>
     */
    public function monthlyTrend(string $domain, ?int $locationId, int $months = 6): array
    {
        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = now()->startOfMonth()->subMonthsNoOverflow($i);
            $statement = $this->incomeStatement($domain, $locationId, $monthStart, $monthStart->copy()->endOfMonth());
            $trend[] = [
                'month' => $monthStart->format('Y-m'),
                'label' => $monthStart->format('M Y'),
                'revenue' => $statement['revenue'],
                'gross_profit' => $statement['gross_profit'],
                'operating_expenses' => $statement['operating_expenses'],
                'net_profit' => $statement['net_profit'],
            ];
        }

        return $trend;
    }

    /**
     * Everything the Finance dashboard shows: this period, the one before and how they compare.
     *
     * @param  array{period: string, start: Carbon, end: Carbon, previous_start: Carbon, previous_end: Carbon}  $period
     * @return array<string, mixed>
     */
    public function overview(string $domain, ?int $locationId, array $period): array
    {
        $current = $this->incomeStatement($domain, $locationId, $period['start'], $period['end']);
        $previous = $this->incomeStatement($domain, $locationId, $period['previous_start'], $period['previous_end']);
        $moneyIn = $this->moneyIn($domain, $locationId, $period['start'], $period['end']);
        $previousMoneyIn = $this->moneyIn($domain, $locationId, $period['previous_start'], $period['previous_end']);

        return [
            'period' => $this->describePeriod($period),
            'location_id' => $locationId,
            'current' => $current,
            'previous' => $previous,
            'changes' => [
                'revenue' => self::change($current['revenue'], $previous['revenue']),
                'cogs' => self::change($current['cogs'], $previous['cogs']),
                'gross_profit' => self::change($current['gross_profit'], $previous['gross_profit']),
                'operating_expenses' => self::change($current['operating_expenses'], $previous['operating_expenses']),
                'net_profit' => self::change($current['net_profit'], $previous['net_profit']),
                'sales_count' => self::change($current['sales_count'], $previous['sales_count']),
                'gross_margin_pts' => round($current['gross_margin_pct'] - $previous['gross_margin_pct'], 1),
                'net_margin_pts' => round($current['net_margin_pct'] - $previous['net_margin_pct'], 1),
                'total_received' => self::change($moneyIn['total_received'], $previousMoneyIn['total_received']),
            ],
            'expense_breakdown' => $current['expense_breakdown'],
            'previous_expense_breakdown' => $previous['expense_breakdown'],
            'expenses_recorded' => $this->expensesRecorded($domain),
            'payables' => $this->payables(
                $domain,
                $period['start'],
                $period['end'],
                $period['previous_start'],
                $period['previous_end'],
            ),
            'money_in' => $moneyIn,
            'previous_money_in' => $previousMoneyIn,
            'inventory_value' => $this->inventoryValue($domain, $locationId),
            'cash_in_drawer' => $this->cashInDrawer($domain, $locationId),
            'receivables' => $this->receivables(
                $domain,
                $period['start'],
                $period['end'],
                $period['previous_start'],
                $period['previous_end'],
            ),
            'products' => $this->productMargins($domain, $locationId, $period['start'], $period['end']),
            'not_tracked' => ['bank_balances', 'loans', 'equipment', 'owner_investments'],
        ];
    }

    /**
     * The full income statement, from the Profit & Loss report (ProfitAndLossService): sales,
     * cost of goods and stock losses, running costs, other income and other expenses, down to
     * net profit. Kept alongside the sales detail the Finance pages also show.
     *
     * @return array<string, mixed>
     */
    public function incomeStatement(string $domain, ?int $locationId, Carbon $start, Carbon $end): array
    {
        $sales = $this->profitAndLoss($domain, $locationId, $start, $end);
        $pl = $this->pnl->build($domain, $start, $end, $locationId);

        $lines = fn (array $rows, string $type) => array_map(fn ($row) => [
            'category_id' => (int) str_replace('category-', '', $row['key']),
            'name' => $row['label'],
            'type' => $type,
            'amount' => $row['amount'],
        ], $rows);

        return [
            ...$sales,
            'inventory_losses' => $pl['inventory_losses'],
            'gross_profit' => $pl['gross_profit'],
            'gross_margin_pct' => $pl['gross_margin_percent'] ?? 0.0,
            'operating_expenses' => $pl['expenses'],
            'operating_profit' => $pl['operating_profit'],
            'other_income' => $pl['other_income'],
            'other_expenses' => $pl['other_expenses'],
            'net_profit' => $pl['net_profit'],
            'net_margin_pct' => $pl['net_margin_percent'] ?? 0.0,
            'expense_breakdown' => collect([
                ...$lines($pl['expense_lines'], 'operating'),
                ...$lines($pl['other_expense_lines'], 'other'),
            ])->sortByDesc('amount')->values()->all(),
            'business_wide_expenses_excluded' => $pl['memo']['business_wide_expenses_excluded'],
        ];
    }

    /** Whether the business has recorded any expense yet; until it has, net profit is just gross profit. */
    public function expensesRecorded(string $domain): bool
    {
        return Expense::query()->forDomain($domain)->exists()
            || SupplierBill::query()->forDomain($domain)->where('bill_type', 'expense')->exists();
    }

    /**
     * What the business owes its suppliers, what is late and what falls due soon.
     * Supplier bills cover the whole business, so this ignores the location filter.
     *
     * @return array<string, mixed>
     */
    public function payables(string $domain, Carbon $start, Carbon $end, Carbon $previousStart, Carbon $previousEnd): array
    {
        $open = SupplierBill::query()->forDomain($domain)->open()->with('supplier:id,name')->get();
        $today = today();

        $outstanding = round((float) $open->sum('remaining'), 2);
        $overdueBills = $open->filter(fn (SupplierBill $b) => $b->due_date !== null && $b->due_date->lt($today));
        $overdue = round((float) $overdueBills->sum('remaining'), 2);
        $dueSoon = round((float) $open
            ->filter(fn (SupplierBill $b) => $b->due_date !== null && $b->due_date->betweenIncluded($today, $today->copy()->addDays(7)))
            ->sum('remaining'), 2);

        $bySupplier = $open
            ->groupBy('supplier_id')
            ->map(fn ($bills) => [
                'supplier_id' => (int) $bills->first()->supplier_id,
                'name' => $bills->first()->supplier?->name ?? 'Unknown supplier',
                'balance' => round((float) $bills->sum('remaining'), 2),
                'overdue' => round((float) $bills->filter(fn ($b) => $b->due_date !== null && $b->due_date->lt($today))->sum('remaining'), 2),
                'open_bills' => $bills->count(),
            ])
            ->sortByDesc('balance')
            ->values();

        $upcoming = $open
            ->filter(fn (SupplierBill $b) => $b->due_date !== null && $b->due_date->gte($today) && $b->due_date->lte($today->copy()->addDays(30)))
            ->sortBy('due_date')
            ->take(10)
            ->map(fn (SupplierBill $b) => $this->billSummary($b))
            ->values()
            ->all();

        $recentlyPaid = SupplierPayment::query()
            ->forDomain($domain)
            ->with('bill.supplier:id,name')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (SupplierPayment $p) => [
                'id' => $p->id,
                'supplier' => $p->bill?->supplier?->name ?? 'Unknown supplier',
                'bill_number' => $p->bill?->bill_number,
                'payment_date' => $p->payment_date->toDateString(),
                'amount' => round((float) $p->amount, 2),
            ])
            ->all();

        return [
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'due_within_7_days' => $dueSoon,
            'open_bills' => $open->count(),
            'suppliers_owed' => $bySupplier->count(),
            'largest' => $bySupplier->take(10)->all(),
            'upcoming' => $upcoming,
            'overdue_bills' => $overdueBills->sortBy('due_date')->take(10)->map(fn ($b) => $this->billSummary($b))->values()->all(),
            'recently_paid' => $recentlyPaid,
            'period' => [
                'billed' => $this->billedTotal($domain, $start, $end),
                'paid' => $this->supplierPaidTotal($domain, null, $start, $end),
            ],
            'previous_period' => [
                'billed' => $this->billedTotal($domain, $previousStart, $previousEnd),
                'paid' => $this->supplierPaidTotal($domain, null, $previousStart, $previousEnd),
            ],
        ];
    }

    /**
     * Where money came from and where it went in a period (direct method), across the cash
     * drawer, e-wallets, card terminals and bank channels together. Also shows why the change in
     * money differs from net profit.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(string $domain, ?int $locationId, Carbon $start, Carbon $end): array
    {
        $moneyIn = $this->moneyIn($domain, $locationId, $start, $end);
        $statement = $this->incomeStatement($domain, $locationId, $start, $end);

        $customerPayments = round($moneyIn['cash'] + $moneyIn['card'] + $moneyIn['e-wallet'] + $moneyIn['bank'], 2);
        $otherIncome = $statement['other_income'];

        $expensesPaid = round((float) Expense::query()
            ->forDomain($domain)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->when($locationId, fn ($q, $id) => $q->where('location_id', $id))
            ->sum('amount'), 2);
        $stockPaid = $this->supplierPaidTotal($domain, $locationId, $start, $end, 'inventory');
        $billedExpensesPaid = $this->supplierPaidTotal($domain, $locationId, $start, $end, 'expense');
        $ownerDraws = round((float) WalletCashMovement::query()
            ->forDomain($domain)
            ->where('kind', 'owner_draw')
            ->whereBetween('movement_date', [$start->toDateString(), $end->toDateString()])
            ->when($locationId, fn ($q, $id) => $q->where('location_id', $id))
            ->sum('amount'), 2);

        $in = [
            'customer_payments' => $customerPayments,
            'credit_collections' => $moneyIn['credit_collections'],
            'other_income' => $otherIncome,
        ];
        $out = [
            'stock_purchases' => $stockPaid,
            'operating_expenses' => round($expensesPaid + $billedExpensesPaid, 2),
            'owner_withdrawals' => $ownerDraws,
        ];
        $totalIn = round(array_sum($in), 2);
        $totalOut = round(array_sum($out), 2);
        $netChange = round($totalIn - $totalOut, 2);

        // Why profit and money don't move together.
        $billedExpenses = round((float) collect($statement['expense_breakdown'])->sum('amount') - $expensesPaid, 2);
        $bridge = [
            ['key' => 'vat', 'label' => 'VAT collected (held for the government, not profit)', 'amount' => $statement['vat']],
            ['key' => 'cogs', 'label' => 'Cost of goods sold (stock paid for earlier)', 'amount' => $statement['cogs']],
            ['key' => 'stock_purchases', 'label' => 'Stock bought from suppliers and paid', 'amount' => -$stockPaid],
            ['key' => 'credit', 'label' => 'Sold on credit and not yet collected', 'amount' => round($moneyIn['credit_collections'] - $moneyIn['sold_on_credit'], 2)],
            ['key' => 'unpaid_bills', 'label' => 'Expense bills not yet paid', 'amount' => round($billedExpenses - $billedExpensesPaid, 2)],
            ['key' => 'owner_withdrawals', 'label' => 'Owner withdrawals', 'amount' => -$ownerDraws],
        ];
        $explained = $statement['net_profit'] + array_sum(array_column($bridge, 'amount'));
        if (abs($netChange - $explained) >= 0.01) {
            $bridge[] = ['key' => 'other', 'label' => 'Other differences', 'amount' => round($netChange - $explained, 2)];
        }

        return [
            'in' => $in,
            'out' => $out,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'net_change' => $netChange,
            'net_profit' => $statement['net_profit'],
            'bridge' => array_values(array_filter($bridge, fn ($row) => abs($row['amount']) >= 0.01)),
            'money_in_by_method' => $moneyIn,
        ];
    }

    /**
     * A simple picture of what the business owns and owes today. Whole business only: customer
     * credit and supplier bills aren't kept per store.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(string $domain): array
    {
        $cash = $this->cashInDrawer($domain, null);
        $inventory = $this->inventoryValue($domain, null);
        $receivables = round((float) Customer::query()->forDomain($domain)->where('credit_balance', '>', 0)->sum('credit_balance'), 2);
        $payables = round((float) SupplierBill::query()->forDomain($domain)->open()->get()->sum('remaining'), 2);

        $assets = [
            ['key' => 'cash_in_drawer', 'label' => 'Cash in drawers (today, expected)', 'amount' => $cash['amount']],
            ['key' => 'inventory', 'label' => 'Inventory (at cost)', 'amount' => $inventory],
            ['key' => 'receivables', 'label' => 'Owed to you by customers', 'amount' => $receivables],
        ];
        $liabilities = [
            ['key' => 'payables', 'label' => 'Owed to suppliers', 'amount' => $payables],
        ];
        $totalAssets = round(array_sum(array_column($assets, 'amount')), 2);
        $totalLiabilities = round(array_sum(array_column($liabilities, 'amount')), 2);

        return [
            'as_of' => today()->toDateString(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'net_worth' => round($totalAssets - $totalLiabilities, 2),
            'cash_locations_counted' => $cash['locations_counted'],
            'not_tracked' => ['Bank accounts', 'Equipment and other assets', 'Loans', 'Owner investments'],
        ];
    }

    /** @return array<string, mixed> */
    private function billSummary(SupplierBill $bill): array
    {
        return [
            'id' => $bill->id,
            'supplier' => $bill->supplier?->name ?? 'Unknown supplier',
            'bill_number' => $bill->bill_number,
            'due_date' => $bill->due_date?->toDateString(),
            'days_overdue' => $bill->due_date !== null && $bill->due_date->lt(today()) ? (int) $bill->due_date->diffInDays(today()) : 0,
            'remaining' => $bill->remaining,
        ];
    }

    private function billedTotal(string $domain, Carbon $start, Carbon $end): float
    {
        return round((float) SupplierBill::query()
            ->forDomain($domain)
            ->whereBetween('bill_date', [$start->toDateString(), $end->toDateString()])
            ->sum('amount'), 2);
    }

    /** Paid to suppliers in a period, optionally only for stock or only for expense bills. */
    private function supplierPaidTotal(string $domain, ?int $locationId, Carbon $start, Carbon $end, ?string $billType = null): float
    {
        return round((float) SupplierPayment::query()
            ->forDomain($domain)
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->when($locationId, fn ($q, $id) => $q->whereHas('bill', fn ($b) => $b->where('location_id', $id)))
            ->when($billType, fn ($q, $type) => $q->whereHas('bill', fn ($b) => $b->where('bill_type', $type)))
            ->sum('amount'), 2);
    }

    /**
     * @param  array{period: string, start: Carbon, end: Carbon, previous_start: Carbon, previous_end: Carbon}  $period
     * @return array<string, string>
     */
    public function describePeriod(array $period): array
    {
        return [
            'key' => $period['period'],
            'start_date' => $period['start']->toDateString(),
            'end_date' => $period['end']->toDateString(),
            'previous_start_date' => $period['previous_start']->toDateString(),
            'previous_end_date' => $period['previous_end']->toDateString(),
        ];
    }

    /**
     * How a figure moved: the difference and the percentage change (null when there is nothing
     * to compare with, so 0 → 500 isn't shown as "+∞%").
     *
     * @return array{amount: float, pct: float|null}
     */
    public static function change(float|int $current, float|int $previous): array
    {
        return [
            'amount' => round($current - $previous, 2),
            'pct' => $previous != 0 ? round(($current - $previous) / abs($previous) * 100, 1) : null,
        ];
    }

    /** Completed, paid sales: voided, refunded and still-open sales count for nothing. */
    private function paidSales(string $domain, ?int $locationId, Carbon $start, Carbon $end): Builder
    {
        return Sale::query()
            ->where('domain', $domain)
            ->where('payment_status', 'paid')
            ->whereBetween('transaction_date', [$start, $end])
            ->when($locationId, fn ($q, $id) => $q->where('location_id', $id));
    }

    private function creditTotal(string $domain, string $type, Carbon $start, Carbon $end): float
    {
        return round((float) CreditTransaction::query()
            ->forDomain($domain)
            ->where('transaction_type', $type)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount'), 2);
    }

    /** @return array<int, int> */
    private function locationIds(string $domain, ?int $locationId): array
    {
        return InventoryLocation::query()
            ->forDomain($domain)
            ->when($locationId, fn ($q, $id) => $q->whereKey($id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
