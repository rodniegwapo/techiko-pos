<?php

namespace App\Services\Finance;

/**
 * Turns the figures from FinancialReportService into a plain health check and a short list of
 * suggestions. Fixed rules, no AI: the same numbers always give the same verdict, so the AI
 * explanation can lean on it without making anything up.
 */
class FinancialInsightService
{
    /** A move smaller than this (in %) counts as "stable". */
    private const TREND_THRESHOLD_PCT = 5.0;

    /** Gross margin falling by this many points or more needs a look. */
    private const MARGIN_DROP_PTS = 3.0;

    /** Share of customer credit that is overdue before it needs attention. */
    private const OVERDUE_ALERT_PCT = 20.0;

    /** Products earning less than this on each sale are flagged. */
    private const LOW_MARGIN_PCT = 15.0;

    /** Stock lasting longer than this many days at the current pace is "high". */
    private const HIGH_STOCK_DAYS = 90;

    /** Operating expenses growing by this much (and faster than sales) need a look. */
    private const EXPENSE_RISE_PCT = 10.0;

    private const LOW_STOCK_DAYS = 7;

    /**
     * @param  array<string, mixed>  $overview  FinancialReportService::overview()
     * @return array<int, array{key: string, label: string, status: string, tone: string, detail: string}>
     */
    public function health(array $overview): array
    {
        return [
            $this->salesHealth($overview),
            $this->profitHealth($overview),
            $this->cashHealth($overview),
            $this->inventoryHealth($overview),
            $this->creditHealth($overview),
            $this->supplierHealth($overview),
            $this->expenseHealth($overview),
        ];
    }

    /**
     * Practical next steps, most pressing first. Suggestions only, not financial advice.
     *
     * @param  array<string, mixed>  $overview
     * @return array<int, array{key: string, priority: int, title: string, detail: string}>
     */
    public function recommendations(array $overview): array
    {
        $current = $overview['current'];
        $changes = $overview['changes'];
        $ar = $overview['receivables'];
        $ap = $overview['payables'];
        $out = [];

        if ($overview['expenses_recorded'] && $current['net_profit'] < 0 && $current['revenue'] > 0) {
            $out[] = [
                'key' => 'net_loss',
                'priority' => 1,
                'title' => 'You made a loss this period',
                'detail' => 'Expenses of '.self::peso($current['operating_expenses'] + $current['other_expenses'])
                    .' were more than the '.self::peso($current['gross_profit']).' gross profit from sales, a net loss of '
                    .self::peso(abs($current['net_profit'])).'.',
            ];
        }

        if ($ap['overdue'] > 0) {
            $out[] = [
                'key' => 'pay_overdue_bills',
                'priority' => 1,
                'title' => 'Settle or reschedule overdue supplier bills',
                'detail' => self::peso($ap['overdue']).' of supplier bills is past due. Talk to those suppliers before it hurts the relationship.',
            ];
        }

        if ($ap['due_within_7_days'] > 0) {
            $out[] = [
                'key' => 'bills_due_soon',
                'priority' => 2,
                'title' => 'Set aside money for supplier bills due this week',
                'detail' => self::peso($ap['due_within_7_days']).' is due to suppliers within the next 7 days.',
            ];
        }

        $expenseChange = $changes['operating_expenses']['pct'];
        $revenueChange = $changes['revenue']['pct'] ?? 0;
        if ($expenseChange !== null && $expenseChange >= self::EXPENSE_RISE_PCT && $expenseChange > $revenueChange + self::TREND_THRESHOLD_PCT) {
            $grower = $this->fastestGrowingCategory($overview);
            $out[] = [
                'key' => 'expenses_rising',
                'priority' => 2,
                'title' => 'Review your increasing operating expenses',
                'detail' => 'Operating expenses rose '.$expenseChange.'% while sales moved '.$revenueChange.'%.'
                    .($grower ? ' The biggest increase was '.$grower['name'].' (+'.self::peso($grower['increase']).').' : ''),
            ];
        }

        if (! $overview['expenses_recorded']) {
            $out[] = [
                'key' => 'record_expenses',
                'priority' => 3,
                'title' => 'Record your expenses to see your real profit',
                'detail' => 'No expenses have been recorded yet, so net profit only reflects product costs. Add rent, salaries, utilities and other running costs under Finance → Expenses.',
            ];
        }

        if ($ar['overdue'] > 0) {
            $count = count($ar['overdue_customers']);
            $out[] = [
                'key' => 'collect_overdue',
                'priority' => $ar['overdue_pct'] >= self::OVERDUE_ALERT_PCT ? 1 : 3,
                'title' => 'Follow up with customers whose credit payments are overdue',
                'detail' => self::peso($ar['overdue']).' is overdue across '.$count.' '.($count === 1 ? 'customer' : 'customers')
                    .' ('.$ar['overdue_pct'].'% of all customer credit).',
            ];
        }

        if ($changes['gross_margin_pts'] <= -self::MARGIN_DROP_PTS && $overview['previous']['revenue'] > 0) {
            $out[] = [
                'key' => 'margin_drop',
                'priority' => 1,
                'title' => 'Check product costs, prices and discounts',
                'detail' => 'You kept '.$current['gross_margin_pct'].'% of each sale as gross profit, down from '
                    .$overview['previous']['gross_margin_pct'].'%. Costs or discounts grew faster than prices.',
            ];
        }

        $lowMargin = array_values(array_filter(
            $overview['products']['low_margin'],
            fn ($p) => $p['margin_pct'] < self::LOW_MARGIN_PCT,
        ));
        if ($lowMargin !== []) {
            $names = implode(', ', array_map(fn ($p) => $p['name'].' ('.$p['margin_pct'].'%)', array_slice($lowMargin, 0, 3)));
            $out[] = [
                'key' => 'low_margin_products',
                'priority' => 2,
                'title' => 'Review products with low profit margins',
                'detail' => 'These earn less than '.self::LOW_MARGIN_PCT.'% per sale: '.$names.'.',
            ];
        }

        $stockDays = $this->stockDays($overview);
        if ($stockDays !== null && $stockDays > self::HIGH_STOCK_DAYS) {
            $out[] = [
                'key' => 'high_inventory',
                'priority' => 2,
                'title' => 'Buy less of slow-moving products',
                'detail' => 'At the current pace of sales, your '.self::peso($overview['inventory_value'])
                    .' of stock would last '.self::duration($stockDays).'. That cash is tied up on the shelf.',
            ];
        }

        if (($changes['revenue']['pct'] ?? 0) <= -self::TREND_THRESHOLD_PCT) {
            $out[] = [
                'key' => 'sales_decline',
                'priority' => 2,
                'title' => 'Look into why sales dropped',
                'detail' => 'Revenue fell '.abs($changes['revenue']['pct']).'% compared with the previous period. '
                    .'Compare your best sellers and busy days with last period.',
            ];
        }

        if ($ar['outstanding'] > 0 && $ar['top_three_share_pct'] > 50) {
            $out[] = [
                'key' => 'credit_concentration',
                'priority' => 3,
                'title' => 'Keep a close eye on your biggest credit customers',
                'detail' => 'Three customers hold '.$ar['top_three_share_pct'].'% of everything owed to you. '
                    .'A late payment from one of them would hurt.',
            ];
        }

        if ($current['items_missing_cost'] > 0) {
            $out[] = [
                'key' => 'missing_costs',
                'priority' => 3,
                'title' => 'Add cost prices to your products',
                'detail' => $current['items_missing_cost'].' sold '.($current['items_missing_cost'] === 1 ? 'item has' : 'items have')
                    .' no cost recorded, so the profit shown is higher than it really is.',
            ];
        }

        usort($out, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return $out;
    }

    /** @param  array<string, mixed>  $overview */
    private function salesHealth(array $overview): array
    {
        $change = $overview['changes']['revenue'];
        [$status, $tone] = $this->trend($overview['current']['revenue'], $overview['previous']['revenue'], $change['pct']);

        return [
            'key' => 'sales',
            'label' => 'Sales',
            'status' => $status,
            'tone' => $tone,
            'detail' => 'Revenue '.self::peso($overview['current']['revenue']).self::versus($change, $overview['previous']['revenue']).'.',
        ];
    }

    /** @param  array<string, mixed>  $overview */
    private function profitHealth(array $overview): array
    {
        if ($overview['expenses_recorded']) {
            return $this->netProfitHealth($overview);
        }

        $change = $overview['changes']['gross_profit'];
        $marginMove = $overview['changes']['gross_margin_pts'];
        [$status, $tone] = $this->trend($overview['current']['gross_profit'], $overview['previous']['gross_profit'], $change['pct']);

        if (($change['pct'] !== null && $change['pct'] <= -self::TREND_THRESHOLD_PCT)
            || ($marginMove <= -self::MARGIN_DROP_PTS && $overview['previous']['revenue'] > 0)) {
            [$status, $tone] = ['Needs attention', 'bad'];
        }

        return [
            'key' => 'profit',
            'label' => 'Gross profit',
            'status' => $status,
            'tone' => $tone,
            'detail' => 'Gross profit '.self::peso($overview['current']['gross_profit'])
                .self::versus($change, $overview['previous']['gross_profit'])
                .'; you keep '.$overview['current']['gross_margin_pct'].'% of each sale after product costs.',
        ];
    }

    /** @param  array<string, mixed>  $overview */
    private function netProfitHealth(array $overview): array
    {
        $current = $overview['current'];
        $change = $overview['changes']['net_profit'];
        [$status, $tone] = $this->trend($current['net_profit'], $overview['previous']['net_profit'], $change['pct']);

        if ($current['net_profit'] < 0 && $current['revenue'] > 0) {
            [$status, $tone] = ['Loss', 'bad'];
        } elseif (($change['pct'] !== null && $change['pct'] <= -self::TREND_THRESHOLD_PCT)
            || ($overview['changes']['net_margin_pts'] <= -self::MARGIN_DROP_PTS && $overview['previous']['revenue'] > 0)) {
            [$status, $tone] = ['Needs attention', 'bad'];
        }

        return [
            'key' => 'profit',
            'label' => 'Net profit',
            'status' => $status,
            'tone' => $tone,
            'detail' => 'Net profit '.self::peso($current['net_profit'])
                .self::versus($change, $overview['previous']['net_profit'])
                .'; you keep '.$current['net_margin_pct'].'% of each sale after all costs.',
        ];
    }

    /** @param  array<string, mixed>  $overview */
    private function expenseHealth(array $overview): array
    {
        if (! $overview['expenses_recorded']) {
            return [
                'key' => 'expenses',
                'label' => 'Expenses',
                'status' => 'Not recorded yet',
                'tone' => 'muted',
                'detail' => 'No expenses recorded yet, so net profit only counts product costs.',
            ];
        }

        $current = $overview['current']['operating_expenses'];
        $previous = $overview['previous']['operating_expenses'];
        $pct = $overview['changes']['operating_expenses']['pct'];
        $revenuePct = $overview['changes']['revenue']['pct'] ?? 0;

        [$status, $tone] = match (true) {
            $current == 0 && $previous == 0 => ['None this period', 'muted'],
            $pct === null => ['Increasing', 'warning'],
            $pct >= self::EXPENSE_RISE_PCT && $pct > $revenuePct + self::TREND_THRESHOLD_PCT => ['Increasing', 'warning'],
            $pct <= -self::TREND_THRESHOLD_PCT => ['Decreasing', 'good'],
            default => ['Stable', 'neutral'],
        };

        $top = $overview['expense_breakdown'][0] ?? null;

        return [
            'key' => 'expenses',
            'label' => 'Expenses',
            'status' => $status,
            'tone' => $tone,
            'detail' => 'Operating expenses '.self::peso($current).self::versus($overview['changes']['operating_expenses'], $previous)
                .($top ? '. Biggest: '.$top['name'].' ('.self::peso($top['amount']).')' : '').'.',
        ];
    }

    /** @param  array<string, mixed>  $overview */
    private function supplierHealth(array $overview): array
    {
        $ap = $overview['payables'];

        [$status, $tone] = match (true) {
            $ap['outstanding'] <= 0 => ['Nothing owed', 'good'],
            $ap['overdue'] > 0 => ['Overdue bills', 'bad'],
            $ap['due_within_7_days'] > 0 => ['Bills due soon', 'warning'],
            default => ['On track', 'good'],
        };

        return [
            'key' => 'suppliers',
            'label' => 'Supplier bills',
            'status' => $status,
            'tone' => $tone,
            'detail' => 'You owe suppliers '.self::peso($ap['outstanding']).'; '.self::peso($ap['overdue']).' is overdue and '
                .self::peso($ap['due_within_7_days']).' is due within 7 days.',
        ];
    }

    /** The expense category that grew the most against the previous period. */
    private function fastestGrowingCategory(array $overview): ?array
    {
        $previous = collect($overview['previous_expense_breakdown'])->keyBy('category_id');

        return collect($overview['expense_breakdown'])
            ->map(fn ($row) => ['name' => $row['name'], 'increase' => round($row['amount'] - ($previous[$row['category_id']]['amount'] ?? 0), 2)])
            ->filter(fn ($row) => $row['increase'] > 0)
            ->sortByDesc('increase')
            ->first();
    }

    /** @param  array<string, mixed>  $overview */
    private function cashHealth(array $overview): array
    {
        $change = $overview['changes']['total_received'];
        [$status, $tone] = $this->trend(
            $overview['money_in']['total_received'],
            $overview['previous_money_in']['total_received'],
            $change['pct'],
        );
        if ($change['pct'] !== null && $change['pct'] <= -10) {
            [$status, $tone] = ['Needs attention', 'bad'];
        }

        return [
            'key' => 'cash',
            'label' => 'Money received',
            'status' => $status,
            'tone' => $tone,
            'detail' => self::peso($overview['money_in']['total_received']).' came in from sales and credit payments'
                .self::versus($change, $overview['previous_money_in']['total_received']).'.',
        ];
    }

    /** @param  array<string, mixed>  $overview */
    private function inventoryHealth(array $overview): array
    {
        $days = $this->stockDays($overview);
        $value = self::peso($overview['inventory_value']);

        if ($overview['inventory_value'] <= 0) {
            return ['key' => 'inventory', 'label' => 'Inventory', 'status' => 'No stock value', 'tone' => 'muted',
                'detail' => 'No stock with a cost is on hand.'];
        }
        if ($days === null) {
            return ['key' => 'inventory', 'label' => 'Inventory', 'status' => 'High', 'tone' => 'warning',
                'detail' => $value.' of stock on hand, but nothing with a cost sold this period.'];
        }

        [$status, $tone] = match (true) {
            $days > self::HIGH_STOCK_DAYS => ['High', 'warning'],
            $days < self::LOW_STOCK_DAYS => ['Low', 'warning'],
            default => ['Healthy', 'good'],
        };

        return [
            'key' => 'inventory',
            'label' => 'Inventory',
            'status' => $status,
            'tone' => $tone,
            'detail' => $value.' of stock on hand, '.self::duration($days).' of sales at the current pace.',
        ];
    }

    /** @param  array<string, mixed>  $overview */
    private function creditHealth(array $overview): array
    {
        $ar = $overview['receivables'];
        $new = $ar['period']['new_credit'];
        $collected = $ar['period']['collected'];

        [$status, $tone] = match (true) {
            $ar['outstanding'] <= 0 => ['None owed', 'good'],
            $ar['overdue_pct'] >= self::OVERDUE_ALERT_PCT => ['Needs attention', 'bad'],
            $new > $collected * 1.2 && $new > 0 => ['Increasing', 'warning'],
            $collected > $new => ['Improving', 'good'],
            default => ['Stable', 'neutral'],
        };

        return [
            'key' => 'credit',
            'label' => 'Customer credit',
            'status' => $status,
            'tone' => $tone,
            'detail' => 'Customers owe '.self::peso($ar['outstanding']).', of which '.self::peso($ar['overdue'])
                .' is overdue. This period: '.self::peso($new).' given on credit, '.self::peso($collected).' collected.',
        ];
    }

    /** How many days the stock on hand would last at this period's pace of sales (null: nothing sold). */
    private function stockDays(array $overview): ?int
    {
        $start = \Carbon\Carbon::parse($overview['period']['start_date']);
        $end = \Carbon\Carbon::parse($overview['period']['end_date']);
        $days = max(1, (int) round($start->diffInDays($end)) + 1);
        $dailyCost = $overview['current']['cogs'] / $days;

        return $dailyCost > 0 ? (int) round($overview['inventory_value'] / $dailyCost) : null;
    }

    /** @return array{0: string, 1: string} */
    private function trend(float|int $current, float|int $previous, ?float $pct): array
    {
        if ($current == 0 && $previous == 0) {
            return ['No activity', 'muted'];
        }
        if ($pct === null) {
            return ['Improving', 'good'];
        }

        return match (true) {
            $pct >= self::TREND_THRESHOLD_PCT => ['Improving', 'good'],
            $pct <= -self::TREND_THRESHOLD_PCT => ['Declining', 'bad'],
            default => ['Stable', 'neutral'],
        };
    }

    /** @param  array{amount: float, pct: float|null}  $change */
    private static function versus(array $change, float|int $previous): string
    {
        if ($change['pct'] === null) {
            return $previous == 0 ? ' (nothing to compare with last period)' : '';
        }
        $direction = $change['pct'] >= 0 ? 'up' : 'down';

        return ', '.$direction.' '.abs($change['pct']).'% from '.self::peso($previous);
    }

    /** "about 45 days"; anything past a year just reads "more than a year". */
    private static function duration(int $days): string
    {
        return $days > 365 ? 'more than a year' : 'about '.$days.' '.($days === 1 ? 'day' : 'days');
    }

    public static function peso(float|int $amount): string
    {
        return ($amount < 0 ? '-' : '').'₱'.number_format(abs($amount), 2);
    }
}
