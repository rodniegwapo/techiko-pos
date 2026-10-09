<?php

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Finance\OtherIncome;
use App\Models\Finance\SupplierBill;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Profit & Loss for one period, worked out from the app's own records — never estimated.
 *
 *   Net sales (what customers paid, without VAT)
 * − Cost of goods sold (cost frozen on each sold line)
 * − Inventory losses (approved stock write-offs, at cost)
 * = Gross profit
 * − Operating expenses (Expenses module, plus running costs billed by suppliers)
 * = Operating profit
 * + Other income
 * − Other expenses (categories of type "other": interest, losses, one-off costs)
 * = Net profit before income tax
 *
 * Revenue is counted on paid sales by transaction date. Credit sales are marked paid when made,
 * so they count when sold (accrual), not when collected. Expenses billed by a supplier count on
 * the bill date, paid or not; stock bought from suppliers is not an expense (it becomes cost of
 * goods sold when sold).
 */
class ProfitAndLossService
{
    /** Stock adjustment reasons shown as their own loss lines; the rest fall under "Other write-offs". */
    private const LOSS_REASONS = [
        'damaged_goods' => 'Damaged goods',
        'expired_goods' => 'Expired goods',
        'theft_loss' => 'Theft / missing stock',
    ];

    public function build(string $domainSlug, CarbonInterface $start, CarbonInterface $end, ?int $locationId = null): array
    {
        $sales = $this->paidSales($domainSlug, $start, $end, $locationId);

        $totals = (clone $sales)
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as grand_total')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as vat')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) + COALESCE(SUM(loyalty_discount_amount), 0) as order_discounts')
            ->first();

        $lineDiscounts = (float) SaleItem::query()->whereIn('sale_id', (clone $sales)->select('sales.id'))->sum('discount');
        $netSales = (float) $totals->grand_total - (float) $totals->vat;

        ['cogs' => $cogs, 'items_missing_cost' => $missingCost] = self::costOfGoodsSold($sales);

        $losses = $this->inventoryLosses($domainSlug, $start, $end, $locationId);
        $lossTotal = array_sum(array_column($losses, 'amount'));

        $grossProfit = $netSales - $cogs - $lossTotal;

        [
            'lines' => $expenseLines,
            'other_lines' => $otherExpenseLines,
            'business_wide_excluded' => $businessWideExcluded,
        ] = $this->expenses($domainSlug, $start, $end, $locationId);
        $expenseTotal = array_sum(array_column($expenseLines, 'amount'));
        $otherExpenseTotal = array_sum(array_column($otherExpenseLines, 'amount'));
        $otherIncome = $this->otherIncome($domainSlug, $start, $end, $locationId);

        $operatingProfit = $grossProfit - $expenseTotal;
        $netProfit = $operatingProfit + $otherIncome - $otherExpenseTotal;

        return [
            'net_sales' => round($netSales, 2),
            'cogs' => round($cogs, 2),
            'inventory_losses' => round($lossTotal, 2),
            'inventory_loss_lines' => $losses,
            'gross_profit' => round($grossProfit, 2),
            'expenses' => round($expenseTotal, 2),
            'expense_lines' => $expenseLines,
            'operating_profit' => round($operatingProfit, 2),
            'other_income' => round($otherIncome, 2),
            'other_expenses' => round($otherExpenseTotal, 2),
            'other_expense_lines' => $otherExpenseLines,
            'net_profit' => round($netProfit, 2),
            'gross_margin_percent' => $netSales > 0 ? round($grossProfit / $netSales * 100, 1) : null,
            'net_margin_percent' => $netSales > 0 ? round($netProfit / $netSales * 100, 1) : null,
            'memo' => [
                'sales_count' => (int) $totals->sales_count,
                'vat_collected' => round((float) $totals->vat, 2),
                'discounts_given' => round((float) $totals->order_discounts + $lineDiscounts, 2),
                'items_missing_cost' => $missingCost,
                'business_wide_expenses_excluded' => $businessWideExcluded,
            ],
        ];
    }

    /**
     * Cost of the items still on the given sales: unit cost frozen at sale time × quantity.
     * Voided lines are soft-deleted, so they drop out on their own. Lines whose product had no cost
     * count as zero and are reported so the caller can warn that profit is overstated.
     *
     * @return array{cogs: float, items_missing_cost: int}
     */
    public static function costOfGoodsSold(Builder $salesQuery): array
    {
        $items = SaleItem::query()->whereIn('sale_id', (clone $salesQuery)->select('sales.id'));

        return [
            'cogs' => (float) (clone $items)->whereNotNull('unit_cost')->sum(DB::raw('unit_cost * quantity')),
            'items_missing_cost' => (clone $items)->whereNull('unit_cost')->count(),
        ];
    }

    private function paidSales(string $domainSlug, CarbonInterface $start, CarbonInterface $end, ?int $locationId): Builder
    {
        return Sale::query()
            ->where('domain', $domainSlug)
            ->where('payment_status', 'paid')
            ->whereBetween('transaction_date', [$start, $end])
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId));
    }

    /** @return list<array{key: string, label: string, amount: float}> */
    private function inventoryLosses(string $domainSlug, CarbonInterface $start, CarbonInterface $end, ?int $locationId): array
    {
        $rows = DB::table('stock_adjustment_items as i')
            ->join('stock_adjustments as a', 'a.id', '=', 'i.stock_adjustment_id')
            ->where('a.domain', $domainSlug)
            ->where('a.status', 'approved')
            ->whereBetween('a.approved_at', [$start, $end])
            // Oversell corrections are bookkeeping for stock already sold, not a loss.
            ->where('a.reason', '!=', 'oversell_found')
            ->where('i.adjustment_quantity', '<', 0)
            ->when($locationId !== null, fn ($q) => $q->where('a.location_id', $locationId))
            ->groupBy('a.reason')
            ->selectRaw('a.reason, SUM(-i.adjustment_quantity * i.unit_cost) as amount')
            ->get();

        $lines = [];
        foreach ($rows as $row) {
            $key = array_key_exists($row->reason, self::LOSS_REASONS) ? $row->reason : 'other';
            $lines[$key] ??= ['key' => $key, 'label' => self::LOSS_REASONS[$key] ?? 'Other stock write-offs', 'amount' => 0.0];
            $lines[$key]['amount'] += (float) $row->amount;
        }

        return collect($lines)
            ->map(fn ($line) => ['amount' => round($line['amount'], 2)] + $line)
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * Expenses by category: recorded expenses plus running costs billed by suppliers, split into
     * operating and other by the category's type. With a store chosen, only that store's expenses
     * count; business-wide ones (rent of a head office, accountant…) can't be split per store, so
     * their total is returned separately for a note instead of being silently dropped.
     *
     * @return array{lines: list<array{key: string, label: string, amount: float}>, other_lines: list<array{key: string, label: string, amount: float}>, business_wide_excluded: ?float}
     */
    private function expenses(string $domainSlug, CarbonInterface $start, CarbonInterface $end, ?int $locationId): array
    {
        $range = [$start->toDateString(), $end->toDateString()];
        $recorded = Expense::query()->forDomain($domainSlug)->whereBetween('expense_date', $range);
        $billed = SupplierBill::query()->forDomain($domainSlug)->where('bill_type', 'expense')->whereBetween('bill_date', $range);

        $categories = ExpenseCategory::query()->forDomain($domainSlug)->get(['id', 'name', 'type'])->keyBy('id');

        $totals = [];
        foreach ([$recorded, $billed] as $query) {
            (clone $query)
                ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
                ->groupBy('expense_category_id')
                ->selectRaw('expense_category_id, SUM(amount) as amount')
                ->get()
                ->each(function ($row) use (&$totals) {
                    $totals[$row->expense_category_id] = ($totals[$row->expense_category_id] ?? 0) + (float) $row->amount;
                });
        }

        $lines = ['operating' => [], 'other' => []];
        foreach ($totals as $categoryId => $amount) {
            $category = $categories[$categoryId] ?? null;
            $lines[$category?->type === 'other' ? 'other' : 'operating'][] = [
                'key' => 'category-'.$categoryId,
                'label' => $category?->name ?? 'Other',
                'amount' => round($amount, 2),
            ];
        }
        $byAmount = fn (array $rows) => collect($rows)->sortByDesc('amount')->values()->all();

        $excluded = $locationId !== null
            ? round((float) (clone $recorded)->whereNull('location_id')->sum('amount')
                + (float) (clone $billed)->whereNull('location_id')->sum('amount'), 2)
            : null;

        return [
            'lines' => $byAmount($lines['operating']),
            'other_lines' => $byAmount($lines['other']),
            'business_wide_excluded' => $excluded,
        ];
    }

    /** Money earned outside of sales. With a store chosen, only that store's income counts. */
    private function otherIncome(string $domainSlug, CarbonInterface $start, CarbonInterface $end, ?int $locationId): float
    {
        return (float) OtherIncome::query()
            ->forDomain($domainSlug)
            ->whereBetween('income_date', [$start->toDateString(), $end->toDateString()])
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->sum('amount');
    }
}
