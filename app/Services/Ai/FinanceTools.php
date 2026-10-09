<?php

namespace App\Services\Ai;

use App\Services\Finance\FinancialInsightService;
use App\Services\Finance\FinancialReportService;
use App\Services\Finance\FinancialSnapshotService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Throwable;

/**
 * The tools Claude can call to answer an owner's question. Every figure comes from
 * FinancialReportService, always for this business (and store, when one is chosen); Claude only
 * picks which figures it needs and words the answer. Customer names are left out.
 */
class FinanceTools
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly FinancialInsightService $insights,
        private readonly string $domain,
        private readonly ?int $locationId,
    ) {}

    /** @return list<array<string, mixed>> Tool definitions in the SDK's shape. */
    public function definitions(): array
    {
        $range = [
            'start_date' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD'],
            'end_date' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD'],
        ];
        $rangeTool = fn (string $name, string $description) => [
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $range, 'required' => ['start_date', 'end_date']],
        ];
        $noInput = fn (string $name, string $description) => [
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
        ];

        return [
            $rangeTool('get_income_statement', 'Sales, cost of goods sold, gross profit, operating expenses, other income and expenses and net profit for a date range, with the same figures for the equally long period just before it and how each changed.'),
            [
                'name' => 'get_monthly_trend',
                'description' => 'Revenue, gross profit, operating expenses and net profit for each of the last N months, oldest first. Use for "which month was best", trends and comparisons across months.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['months' => ['type' => 'integer', 'description' => 'How many months back, 1 to 24']],
                    'required' => ['months'],
                ],
            ],
            $rangeTool('get_expenses', 'Expenses by category for a date range and for the equally long period before it.'),
            $rangeTool('get_cash_flow', 'Money in and out for a date range: customer payments, collections, other income, loans, owner investments, stock bought, expenses paid, loan repayments, equipment bought, owner withdrawals, the net change, and why it differs from net profit.'),
            $rangeTool('get_products', 'Best-selling products with their profit margins, the lowest-margin products for a date range, and slow-moving stock (as of today).'),
            $noInput('get_customer_credit', 'What customers owe today: total, overdue, how late, due soon, the largest balances (customers are anonymised as Customer A, B…), and credit given and collected this month.'),
            $noInput('get_supplier_bills', 'What the business owes suppliers today: total, overdue, due within 7 days, upcoming and overdue bills, largest supplier balances, recent payments.'),
            $noInput('get_balance_sheet', 'What the business owns and owes today (cash, bank, e-wallets, inventory, customer credit, equipment; supplier bills, loans, other amounts owed) and the owner\'s share, including accumulated profit.'),
            [
                'name' => 'get_balance_history',
                'description' => 'How balances stood at the end of each of the last N months (the last daily snapshot of each month): cash in drawers, bank and e-wallet balances, inventory value, what customers owed (and how much was overdue), supplier bills, loans, net worth. Use for "why is my inventory value increasing", "why do customers owe me more", "why is my cash low". History only exists from when snapshots started.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['months' => ['type' => 'integer', 'description' => 'How many months back, 1 to 24']],
                    'required' => ['months'],
                ],
            ],
            $rangeTool('get_business_health', 'The business health check (sales, profit, money received, inventory, customer credit, supplier bills, expenses) and suggested actions for a date range compared with the period before it.'),
        ];
    }

    /** Runs a tool and returns its result as JSON text (an error message when the input is unusable). */
    public function run(string $name, array $input): string
    {
        try {
            $result = match ($name) {
                'get_income_statement' => $this->incomeStatement(...$this->range($input)),
                'get_monthly_trend' => $this->reports->monthlyTrend($this->domain, $this->locationId, max(1, min(24, (int) ($input['months'] ?? 6)))),
                'get_expenses' => $this->expenses(...$this->range($input)),
                'get_cash_flow' => Arr::except($this->reports->cashFlow($this->domain, $this->locationId, ...$this->range($input)), ['money_in_by_method']),
                'get_products' => [
                    ...$this->reports->productMargins($this->domain, $this->locationId, ...[...$this->range($input), 10]),
                    'slow_movers' => $this->reports->slowMovers($this->domain, $this->locationId),
                ],
                'get_customer_credit' => $this->customerCredit(),
                'get_supplier_bills' => $this->supplierBills(),
                'get_balance_sheet' => $this->reports->balanceSheet($this->domain),
                'get_balance_history' => [
                    'month_ends' => app(FinancialSnapshotService::class)->monthEnds($this->domain, max(1, min(24, (int) ($input['months'] ?? 6)))),
                    'note' => 'Each month shows its last saved day; the current month shows the latest day so far.',
                ],
                'get_business_health' => $this->health(...$this->range($input)),
                default => ['error' => 'Unknown tool '.$name],
            };
        } catch (Throwable $e) {
            report($e);
            $result = ['error' => 'Could not get those figures: '.$e->getMessage()];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(array $input): array
    {
        $start = Carbon::parse($input['start_date'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $end = Carbon::parse($input['end_date'] ?? now()->toDateString())->endOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    /** The equally long period just before a range. */
    private function previous(Carbon $start, Carbon $end): array
    {
        $days = (int) round($start->diffInDays($end->copy()->startOfDay())) + 1;

        return [$start->copy()->subDays($days), $start->copy()->subDay()->endOfDay()];
    }

    private function incomeStatement(Carbon $start, Carbon $end): array
    {
        [$prevStart, $prevEnd] = $this->previous($start, $end);
        $current = $this->reports->incomeStatement($this->domain, $this->locationId, $start, $end);
        $previous = $this->reports->incomeStatement($this->domain, $this->locationId, $prevStart, $prevEnd);
        $keys = ['revenue', 'cogs', 'gross_profit', 'operating_expenses', 'other_income', 'other_expenses', 'net_profit'];

        return [
            'period' => [$start->toDateString(), $end->toDateString()],
            'previous_period' => [$prevStart->toDateString(), $prevEnd->toDateString()],
            'current' => Arr::except($current, ['expense_breakdown']),
            'previous' => Arr::only($previous, [...$keys, 'gross_margin_pct', 'net_margin_pct', 'sales_count']),
            'changes' => collect($keys)->mapWithKeys(fn ($k) => [$k => FinancialReportService::change($current[$k], $previous[$k])])->all(),
            'expenses_recorded' => $this->reports->expensesRecorded($this->domain),
        ];
    }

    private function expenses(Carbon $start, Carbon $end): array
    {
        [$prevStart, $prevEnd] = $this->previous($start, $end);

        return [
            'period' => [$start->toDateString(), $end->toDateString()],
            'by_category' => $this->reports->incomeStatement($this->domain, $this->locationId, $start, $end)['expense_breakdown'],
            'previous_by_category' => $this->reports->incomeStatement($this->domain, $this->locationId, $prevStart, $prevEnd)['expense_breakdown'],
        ];
    }

    private function customerCredit(): array
    {
        $ar = $this->reports->receivables(
            $this->domain,
            now()->startOfMonth(),
            now(),
            now()->startOfMonth()->subMonthNoOverflow(),
            now()->subMonthNoOverflow(),
        );
        $ar['largest_balances'] = array_map(
            fn ($row, $i) => Arr::except($row, ['customer_id', 'name']) + ['customer' => 'Customer '.chr(65 + $i)],
            $ar['largest_balances'],
            array_keys($ar['largest_balances']),
        );
        $ar['overdue_customers'] = array_map(fn ($row) => Arr::only($row, ['overdue', 'days_overdue']), $ar['overdue_customers']);

        return $ar;
    }

    private function supplierBills(): array
    {
        return $this->reports->payables(
            $this->domain,
            now()->startOfMonth(),
            now(),
            now()->startOfMonth()->subMonthNoOverflow(),
            now()->subMonthNoOverflow(),
        );
    }

    private function health(Carbon $start, Carbon $end): array
    {
        [$prevStart, $prevEnd] = $this->previous($start, $end);
        $overview = $this->reports->overview($this->domain, $this->locationId, [
            'period' => 'custom',
            'start' => $start,
            'end' => $end,
            'previous_start' => $prevStart,
            'previous_end' => $prevEnd,
        ]);

        return [
            'health_check' => array_map(fn ($h) => Arr::only($h, ['label', 'status', 'detail']), $this->insights->health($overview)),
            'suggestions' => array_map(fn ($r) => Arr::only($r, ['title', 'detail']), $this->insights->recommendations($overview)),
        ];
    }
}
