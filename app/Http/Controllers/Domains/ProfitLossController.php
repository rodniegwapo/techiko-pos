<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\Ai\FinancialAssistant;
use App\Services\RecurringExpenseService;
use App\Services\Reports\ProfitAndLossService;
use App\Support\FinanceAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profit & Loss statement for a period, side by side with the period before it.
 * Admins see the whole business or any store; managers only their own store.
 */
class ProfitLossController extends Controller
{
    private const PRESETS = ['this_month', 'last_month', 'this_quarter', 'this_year', 'custom'];

    public function __construct(
        private ProfitAndLossService $pnl,
        private RecurringExpenseService $recurring,
    ) {}

    public function index(Request $request, Domain $domain)
    {
        $user = $this->authorizeFinance($request);
        // Book recurring expenses that fell due, so the statement isn't missing this month's rent.
        $this->recurring->generateDue($domain->name_slug);

        $period = $this->resolvePeriod($request, $domain, $user);
        $current = $this->pnl->build($domain->name_slug, $period['start'], $period['end'], $period['location_id']);
        $previous = $this->pnl->build($domain->name_slug, $period['prev_start'], $period['prev_end'], $period['location_id']);

        return Inertia::render('Reports/ProfitLoss', [
            'filters' => [
                'preset' => $period['preset'],
                'start_date' => $period['start']->toDateString(),
                'end_date' => $period['end']->toDateString(),
                'location_id' => $period['location_id'],
            ],
            'periods' => [
                'current' => $this->periodLabel($period['start'], $period['end']),
                'previous' => $this->periodLabel($period['prev_start'], $period['prev_end']),
            ],
            'rows' => $this->rows($current, $previous),
            'current' => $current,
            'previous' => $previous,
            'locations' => FinanceAccess::canSeeAllStores($user)
                ? InventoryLocation::query()->forDomain($domain->name_slug)->active()->orderBy('name')->get(['id', 'name'])
                : InventoryLocation::query()->whereKey(FinanceAccess::restrictedLocationId($user) ?? 0)->get(['id', 'name']),
            'canSeeAllStores' => FinanceAccess::canSeeAllStores($user),
            'aiEnabled' => app(FinancialAssistant::class)->isConfigured(),
            'domainName' => $domain->name,
        ]);
    }

    public function export(Request $request, Domain $domain): StreamedResponse
    {
        $user = $this->authorizeFinance($request);
        $period = $this->resolvePeriod($request, $domain, $user);
        $current = $this->pnl->build($domain->name_slug, $period['start'], $period['end'], $period['location_id']);
        $previous = $this->pnl->build($domain->name_slug, $period['prev_start'], $period['prev_end'], $period['location_id']);
        $rows = $this->rows($current, $previous);

        $store = $period['location_id'] ? InventoryLocation::query()->find($period['location_id'])?->name : 'All stores';
        $filename = sprintf(
            'profit-and-loss-%s-%s-%s.csv',
            preg_replace('/[^a-zA-Z0-9_-]+/', '-', $domain->name_slug),
            $period['start']->format('Y-m-d'),
            $period['end']->format('Y-m-d')
        );

        return response()->streamDownload(function () use ($rows, $period, $domain, $store) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['Profit & Loss', $domain->name, $store]);
            fputcsv($out, []);
            fputcsv($out, [
                'Line',
                $this->periodLabel($period['start'], $period['end']),
                $this->periodLabel($period['prev_start'], $period['prev_end']),
                'Change %',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, [
                    str_repeat('    ', $row['indent']).$row['label'],
                    number_format($row['current'], 2, '.', ''),
                    number_format($row['previous'], 2, '.', ''),
                    $row['change_percent'] === null ? '' : $row['change_percent'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The statement as display rows. Group children are the union of both periods so a category
     * that only appears in one of them still lines up.
     *
     * @return list<array{key: string, label: string, type: string, indent: int, current: float, previous: float, change_percent: ?float}>
     */
    private function rows(array $current, array $previous): array
    {
        $rows = [];
        $add = function (string $key, string $label, string $type, float $cur, float $prev, int $indent = 0) use (&$rows) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'indent' => $indent,
                'current' => round($cur, 2),
                'previous' => round($prev, 2),
                'change_percent' => abs($prev) > 0.004 ? round(($cur - $prev) / abs($prev) * 100, 1) : null,
            ];
        };
        $children = function (string $field, string $prefix) use ($current, $previous, $add) {
            $byKey = [];
            foreach ([$current, $previous] as $i => $period) {
                foreach ($period[$field] as $line) {
                    $byKey[$line['key']] ??= ['label' => $line['label'], 0 => 0.0, 1 => 0.0];
                    $byKey[$line['key']][$i] = $line['amount'];
                }
            }
            uasort($byKey, fn ($a, $b) => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);
            foreach ($byKey as $key => $line) {
                $add($prefix.$key, $line['label'], 'detail', $line[0], $line[1], 1);
            }
        };

        $add('net_sales', 'Net sales', 'line', $current['net_sales'], $previous['net_sales']);
        $add('cogs', 'Cost of goods sold', 'line', $current['cogs'], $previous['cogs']);
        $add('inventory_losses', 'Inventory losses', 'group', $current['inventory_losses'], $previous['inventory_losses']);
        $children('inventory_loss_lines', 'loss-');
        $add('gross_profit', 'Gross profit', 'subtotal', $current['gross_profit'], $previous['gross_profit']);
        $add('expenses', 'Operating expenses', 'group', $current['expenses'], $previous['expenses']);
        $children('expense_lines', 'expense-');

        // Only when there is something below operating profit; otherwise it would just repeat net profit.
        $hasOther = fn (array $p) => abs($p['other_income']) > 0.004 || abs($p['other_expenses']) > 0.004;
        if ($hasOther($current) || $hasOther($previous)) {
            $add('operating_profit', 'Operating profit', 'subtotal', $current['operating_profit'], $previous['operating_profit']);
            $add('other_income', 'Other income', 'line', $current['other_income'], $previous['other_income']);
            $add('other_expenses', 'Other expenses', 'group', $current['other_expenses'], $previous['other_expenses']);
            $children('other_expense_lines', 'other-expense-');
        }

        $add('net_profit', 'Net profit (before income tax)', 'total', $current['net_profit'], $previous['net_profit']);

        return $rows;
    }

    /**
     * @return array{preset: string, start: Carbon, end: Carbon, prev_start: Carbon, prev_end: Carbon, location_id: ?int}
     */
    private function resolvePeriod(Request $request, Domain $domain, User $user): array
    {
        $validated = $request->validate([
            'preset' => ['nullable', Rule::in(self::PRESETS)],
            'start_date' => ['nullable', 'required_if:preset,custom', 'date'],
            'end_date' => ['nullable', 'required_if:preset,custom', 'date', 'after_or_equal:start_date'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $preset = $validated['preset'] ?? 'this_month';
        $now = now();

        [$start, $end, $prevStart, $prevEnd] = match ($preset) {
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth(),
                $now->copy()->subMonthsNoOverflow(2)->startOfMonth(), $now->copy()->subMonthsNoOverflow(2)->endOfMonth(),
            ],
            'this_quarter' => [
                $now->copy()->startOfQuarter(), $now->copy()->endOfQuarter(),
                $now->copy()->subQuarterNoOverflow()->startOfQuarter(), $now->copy()->subQuarterNoOverflow()->endOfQuarter(),
            ],
            'this_year' => [
                $now->copy()->startOfYear(), $now->copy()->endOfYear(),
                $now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear(),
            ],
            'custom' => $this->customPeriod($validated['start_date'], $validated['end_date']),
            default => [
                $now->copy()->startOfMonth(), $now->copy()->endOfMonth(),
                $now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
        };

        $locationId = FinanceAccess::restrictedLocationId($user)
            ?? (isset($validated['location_id']) ? (int) $validated['location_id'] : null);
        if ($locationId !== null && ! InventoryLocation::query()->forDomain($domain->name_slug)->whereKey($locationId)->exists()) {
            $locationId = null;
        }
        if (! FinanceAccess::canSeeAllStores($user) && $locationId === null) {
            // A manager without a store sees nothing rather than the whole business.
            $locationId = 0;
        }

        return [
            'preset' => $preset,
            'start' => $start,
            'end' => $end,
            'prev_start' => $prevStart,
            'prev_end' => $prevEnd,
            'location_id' => $locationId,
        ];
    }

    /** A custom range is compared with the same number of days just before it. */
    private function customPeriod(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        $days = (int) $start->diffInDays($end->copy()->startOfDay()) + 1;

        return [$start, $end, $start->copy()->subDays($days), $start->copy()->subDay()->endOfDay()];
    }

    private function periodLabel(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($start->copy()->startOfMonth()) && $end->isSameDay($start->copy()->endOfMonth())) {
            return $start->format('F Y');
        }
        if ($start->isSameDay($start->copy()->startOfYear()) && $end->isSameDay($start->copy()->endOfYear())) {
            return $start->format('Y');
        }

        return $start->format('M j, Y').' – '.$end->format('M j, Y');
    }

    private function authorizeFinance(Request $request): User
    {
        $user = $request->user();
        abort_unless(FinanceAccess::canManage($user), 403);

        return $user;
    }
}
