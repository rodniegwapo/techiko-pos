<?php

namespace App\Services\Finance;

use App\Models\Finance\FinancialSnapshot;

/**
 * Saves the balance sheet figures for today, so inventory, customer credit and cash can later be
 * compared with how they stood before. Taken every night by `finance:snapshot`, and also when
 * the Finance pages are opened, so history builds up even where the scheduler doesn't run.
 */
class FinancialSnapshotService
{
    public function __construct(private readonly FinancialReportService $reports) {}

    /** Today's snapshot (replaced when $refresh, otherwise only taken when missing). */
    public function capture(string $domain, bool $refresh = true): FinancialSnapshot
    {
        $today = today()->toDateString();
        if (! $refresh && ($existing = FinancialSnapshot::query()->forDomain($domain)->whereDate('as_of_date', $today)->first())) {
            return $existing;
        }

        $bs = $this->reports->balanceSheet($domain);
        $line = fn (array $rows, string $key) => (float) (collect($rows)->firstWhere('key', $key)['amount'] ?? 0);
        $overdue = $this->reports->receivables($domain, today(), today(), today(), today())['overdue'];

        return FinancialSnapshot::query()->updateOrCreate(
            ['domain' => $domain, 'as_of_date' => $today],
            [
                'cash_in_drawer' => $line($bs['assets'], 'cash_in_drawer'),
                'accounts_balance' => $line($bs['assets'], 'bank') + $line($bs['assets'], 'ewallet'),
                'inventory_value' => $line($bs['assets'], 'inventory'),
                'receivables' => $line($bs['assets'], 'receivables'),
                'receivables_overdue' => $overdue,
                'fixed_assets' => $line($bs['assets'], 'fixed_assets'),
                'payables' => $line($bs['liabilities'], 'payables'),
                'loans' => $line($bs['liabilities'], 'loans'),
                'other_liabilities' => $line($bs['liabilities'], 'other_liabilities'),
                'total_assets' => $bs['total_assets'],
                'total_liabilities' => $bs['total_liabilities'],
                'net_worth' => $bs['net_worth'],
            ],
        );
    }

    /**
     * Month-end figures for the last few months, oldest first (the last snapshot taken in each
     * month), for comparing balances across months.
     *
     * @return list<array<string, mixed>>
     */
    public function monthEnds(string $domain, int $months = 6): array
    {
        $since = now()->startOfMonth()->subMonthsNoOverflow($months - 1)->toDateString();

        return FinancialSnapshot::query()
            ->forDomain($domain)
            ->where('as_of_date', '>=', $since)
            ->orderBy('as_of_date')
            ->get()
            ->groupBy(fn (FinancialSnapshot $s) => $s->as_of_date->format('Y-m'))
            ->map(fn ($month, $key) => [
                'month' => $key,
                'as_of' => $month->last()->as_of_date->toDateString(),
                'cash_balance' => $month->last()->cashBalance(),
                ...collect(FinancialSnapshot::FIGURES)->mapWithKeys(fn ($f) => [$f => $month->last()->{$f}])->all(),
            ])
            ->values()
            ->all();
    }
}
