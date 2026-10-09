<?php

namespace App\Services\Finance;

use App\Models\Domain;
use App\Models\Finance\MonthlyReview;
use App\Models\SaleItem;
use App\Services\Ai\FinancialAssistant;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A simple health check of one month, saved so the owner can come back to it: the key figures,
 * what went well, what needs attention and what to do next. The lists come from fixed rules over
 * the books, so a review is complete without AI; with AI set up, Claude adds a short summary.
 */
class MonthlyReviewService
{
    /** A product category's sales must move at least this much (in %) to be called out. */
    private const CATEGORY_MOVE_PCT = 15.0;

    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly FinancialInsightService $insights,
        private readonly FinancialAssistant $assistant,
    ) {}

    /** Builds (or rebuilds) the review of a month, e.g. "2026-09". */
    public function generate(Domain $domain, string $month): MonthlyReview
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        if ($start->isSameMonth(now()) || $start->isFuture()) {
            throw new RuntimeException('A month can only be reviewed once it is over.');
        }
        $previousStart = $start->copy()->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->copy()->endOfMonth();

        $overview = $this->reports->overview($domain->name_slug, null, [
            'period' => 'custom',
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
        ]);
        $current = $overview['current'];
        $previous = $overview['previous'];
        $previousLabel = $previousStart->format('F');

        $figures = [
            'sales' => $current['total_paid'],
            'revenue' => $current['revenue'],
            'gross_profit' => $current['gross_profit'],
            'operating_expenses' => $current['operating_expenses'],
            'net_profit' => $current['net_profit'],
            'gross_margin_pct' => $current['gross_margin_pct'],
            'net_margin_pct' => $current['net_margin_pct'],
            'money_received' => $overview['money_in']['total_received'],
            'customers_owe' => $overview['receivables']['outstanding'],
            'owed_to_suppliers' => $overview['payables']['outstanding'],
            'previous' => Arr::only($previous, ['total_paid', 'revenue', 'gross_profit', 'operating_expenses', 'net_profit']),
            'expenses_recorded' => $overview['expenses_recorded'],
        ];

        [$wentWell, $needsAttention] = $this->highlights($overview, $previousLabel);
        $categories = $this->categoryMoves($domain->name_slug, $start, $end, $previousStart, $previousEnd);
        foreach ($categories['up'] as $c) {
            $wentWell[] = $c['name'].' sales performed strongly (+'.$c['pct'].'% to '.FinancialInsightService::peso($c['sales']).').';
        }
        foreach ($categories['down'] as $c) {
            $needsAttention[] = $c['name'].' sales fell '.abs($c['pct']).'% to '.FinancialInsightService::peso($c['sales']).'.';
        }

        $actions = array_map(
            fn ($r) => $r['title'],
            array_slice($this->insights->recommendations($overview), 0, 5),
        );

        $review = MonthlyReview::query()->updateOrCreate(
            ['domain' => $domain->name_slug, 'month' => $month],
            [
                'figures' => $figures,
                'went_well' => array_values(array_slice($wentWell, 0, 5)),
                'needs_attention' => array_values(array_slice($needsAttention, 0, 5)),
                'actions' => $actions,
                'ai_summary' => null,
                'generated_at' => now(),
                'read_at' => null,
            ],
        );

        if ($this->assistant->isConfigured()) {
            try {
                $review->update(['ai_summary' => $this->assistant->explain('review', [
                    'business' => $domain->name,
                    'month' => $review->label,
                    'figures' => $figures,
                    'went_well' => $review->went_well,
                    'needs_attention' => $review->needs_attention,
                    'suggested_actions' => $actions,
                ])['text']]);
            } catch (RuntimeException $e) {
                // The review stands on its own; the summary can be added by generating it again.
                Log::warning('Monthly review summary failed', ['domain' => $domain->name_slug, 'month' => $month, 'message' => $e->getMessage()]);
            }
        }

        return $review->fresh();
    }

    /**
     * What improved and what slipped against the month before, from the figures themselves.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function highlights(array $overview, string $previousLabel): array
    {
        $changes = $overview['changes'];
        $current = $overview['current'];
        $peso = fn ($v) => FinancialInsightService::peso($v);
        $well = [];
        $attention = [];

        $revenue = $changes['revenue']['pct'];
        if ($revenue !== null && $revenue >= 5) {
            $well[] = 'Sales increased '.$revenue.'% compared with '.$previousLabel.'.';
        } elseif ($revenue !== null && $revenue <= -5) {
            $attention[] = 'Sales fell '.abs($revenue).'% compared with '.$previousLabel.'.';
        }

        $net = $changes['net_profit']['pct'];
        if ($overview['expenses_recorded'] && $current['net_profit'] < 0 && $current['revenue'] > 0) {
            $attention[] = 'The month ended with a net loss of '.$peso(abs($current['net_profit'])).'.';
        } elseif ($net !== null && $net >= 5) {
            $well[] = 'Net profit grew '.$net.'% to '.$peso($current['net_profit']).'.';
        } elseif ($net !== null && $net <= -5) {
            $attention[] = 'Net profit dropped '.abs($net).'% to '.$peso($current['net_profit']).'.';
        }

        $margin = $changes['gross_margin_pts'];
        if ($margin >= 2 && $overview['previous']['revenue'] > 0) {
            $well[] = 'You kept more of each sale: gross margin rose to '.$current['gross_margin_pct'].'%.';
        } elseif ($margin <= -2 && $overview['previous']['revenue'] > 0) {
            $attention[] = 'You kept less of each sale: gross margin fell to '.$current['gross_margin_pct'].'%.';
        }

        $expenses = $changes['operating_expenses']['pct'];
        if ($expenses !== null && $expenses >= 10) {
            $attention[] = 'Operating expenses increased '.$expenses.'% to '.$peso($current['operating_expenses']).'.';
        } elseif ($expenses !== null && $expenses <= -10) {
            $well[] = 'Operating expenses went down '.abs($expenses).'%.';
        }

        $ar = $overview['receivables'];
        if ($ar['period']['collected'] > $ar['previous_period']['collected'] && $ar['period']['collected'] > 0) {
            $well[] = 'Customer payments improved: '.$peso($ar['period']['collected']).' collected on credit.';
        }
        if ($ar['period']['new_credit'] > $ar['period']['collected'] * 1.2 && $ar['period']['new_credit'] > 0) {
            $attention[] = 'Customer credit increased: '.$peso($ar['period']['new_credit']).' given, '.$peso($ar['period']['collected']).' collected.';
        }
        if ($ar['overdue'] > 0) {
            $attention[] = $peso($ar['overdue']).' of customer credit is overdue.';
        }

        // Balances at month end against the month before, where the daily snapshots allow it.
        $position = $overview['position'] ?? null;
        if ($position !== null) {
            $move = fn (string $key) => $position['changes'][$key]['pct'];
            $now = $position['now'];

            if (($pct = $move('cash_balance')) !== null && $pct >= 10) {
                $well[] = 'Cash on hand grew '.$pct.'% to '.$peso($now['cash_balance']).'.';
            } elseif ($pct !== null && $pct <= -10) {
                $attention[] = 'Cash on hand fell '.abs($pct).'% to '.$peso($now['cash_balance']).'.';
            }
            if (($pct = $move('inventory_value')) !== null && $pct >= 15) {
                $attention[] = 'Inventory value rose '.$pct.'% to '.$peso($now['inventory_value']).': more money is sitting on the shelf.';
            } elseif ($pct !== null && $pct <= -15) {
                $well[] = 'Less money is tied up in stock: inventory value went down '.abs($pct).'%.';
            }
            if (($pct = $move('receivables')) !== null && $pct >= 10) {
                $attention[] = 'Customers owe more: '.$peso($now['receivables']).' at month end, up '.$pct.'%.';
            } elseif ($pct !== null && $pct <= -10) {
                $well[] = 'Customers owe less: '.$peso($now['receivables']).' at month end, down '.abs($pct).'%.';
            }
        }

        $slow = $overview['products']['slow_movers'] ?? [];
        if ($slow !== []) {
            $attention[] = count($slow).' products are moving slowly, tying up '.$peso(array_sum(array_column($slow, 'value'))).'.';
        }

        if ($overview['payables']['overdue'] > 0) {
            $attention[] = $peso($overview['payables']['overdue']).' of supplier bills is overdue.';
        }

        return [$well, $attention];
    }

    /**
     * Product categories whose sales moved the most against the month before.
     *
     * @return array{up: list<array<string, mixed>>, down: list<array<string, mixed>>}
     */
    private function categoryMoves(string $domain, Carbon $start, Carbon $end, Carbon $previousStart, Carbon $previousEnd): array
    {
        $byCategory = fn (Carbon $from, Carbon $to) => SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.domain', $domain)
            ->where('sales.payment_status', 'paid')
            ->whereBetween('sales.transaction_date', [$from, $to])
            ->groupBy('categories.name')
            ->selectRaw('categories.name as category, SUM(sale_items.subtotal) as sales')
            ->pluck('sales', 'category');

        $now = $byCategory($start, $end);
        $before = $byCategory($previousStart, $previousEnd);

        $moves = $now->map(fn ($sales, $name) => [
            'name' => $name,
            'sales' => round((float) $sales, 2),
            'pct' => (float) ($before[$name] ?? 0) > 0 ? round(((float) $sales - (float) $before[$name]) / (float) $before[$name] * 100, 1) : null,
        ])->filter(fn ($c) => $c['pct'] !== null)->values();

        return [
            'up' => $moves->filter(fn ($c) => $c['pct'] >= self::CATEGORY_MOVE_PCT)->sortByDesc('pct')->take(2)->values()->all(),
            'down' => $moves->filter(fn ($c) => $c['pct'] <= -self::CATEGORY_MOVE_PCT)->sortBy('pct')->take(2)->values()->all(),
        ];
    }
}
