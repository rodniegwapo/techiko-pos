<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Services\Ai\FinancialAssistant;
use App\Services\Finance\FinancialInsightService;
use App\Services\Finance\FinancialReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/**
 * The owner's view of the money: a dashboard, a simple income statement and customer credit,
 * each with an "Explain this" button that puts the figures into plain words.
 */
class FinanceController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly FinancialInsightService $insights,
        private readonly FinancialAssistant $assistant,
    ) {}

    public function dashboard(Request $request, Domain $domain)
    {
        [$period, $locationId] = $this->resolveFilters($request, $domain);
        $overview = $this->reports->overview($domain->name_slug, $locationId, $period);

        return Inertia::render('Finance/Dashboard', [
            ...$this->sharedProps($domain, $period, $locationId),
            'overview' => $overview,
            'health' => $this->insights->health($overview),
            'recommendations' => $this->insights->recommendations($overview),
            'trend' => $this->reports->monthlyTrend($domain->name_slug, $locationId),
        ]);
    }

    /**
     * The income statement is the Profit & Loss report. This keeps the Finance address working
     * and carries the chosen period over in the report's own terms.
     */
    public function incomeStatement(Request $request, Domain $domain): RedirectResponse
    {
        [$period, $locationId] = $this->resolveFilters($request, $domain);

        $query = match ($period['period']) {
            'month' => ['preset' => 'this_month'],
            'year' => ['preset' => 'this_year'],
            default => [
                'preset' => 'custom',
                'start_date' => $period['start']->toDateString(),
                'end_date' => $period['end']->toDateString(),
            ],
        };

        return redirect()->route('domains.profit-loss.index', [
            'domain' => $domain->name_slug,
            ...$query,
            'location_id' => $locationId,
        ]);
    }

    public function cashFlow(Request $request, Domain $domain)
    {
        [$period, $locationId] = $this->resolveFilters($request, $domain);
        $slug = $domain->name_slug;

        return Inertia::render('Finance/CashFlow', [
            ...$this->sharedProps($domain, $period, $locationId),
            'current' => $this->reports->cashFlow($slug, $locationId, $period['start'], $period['end']),
            'previous' => $this->reports->cashFlow($slug, $locationId, $period['previous_start'], $period['previous_end']),
        ]);
    }

    public function balanceSheet(Request $request, Domain $domain)
    {
        [$period, $locationId] = $this->resolveFilters($request, $domain);

        return Inertia::render('Finance/BalanceSheet', [
            ...$this->sharedProps($domain, $period, $locationId),
            'balanceSheet' => $this->reports->balanceSheet($domain->name_slug),
            'payables' => $this->reports->payables(
                $domain->name_slug,
                $period['start'],
                $period['end'],
                $period['previous_start'],
                $period['previous_end'],
            ),
        ]);
    }

    public function receivables(Request $request, Domain $domain)
    {
        [$period, $locationId] = $this->resolveFilters($request, $domain);

        return Inertia::render('Finance/Receivables', [
            ...$this->sharedProps($domain, $period, $locationId),
            'receivables' => $this->reports->receivables(
                $domain->name_slug,
                $period['start'],
                $period['end'],
                $period['previous_start'],
                $period['previous_end'],
            ),
        ]);
    }

    /**
     * Plain-language explanation of the figures on a Finance page, written by Claude from the
     * same numbers the page shows.
     */
    public function explain(Request $request, Domain $domain): JsonResponse
    {
        $validated = $request->validate([
            'topic' => ['required', Rule::in(FinancialAssistant::TOPICS)],
            'metric' => ['required_if:topic,metric', 'nullable', 'string', 'max:80'],
            'language' => ['nullable', Rule::in(FinancialAssistant::LANGUAGES)],
        ]);

        if (! $this->assistant->isConfigured()) {
            return response()->json(['message' => 'The AI assistant is not set up yet. Ask your administrator to add an Anthropic API key.'], 503);
        }

        [$period, $locationId] = $this->resolveFilters($request, $domain);
        $overview = $this->reports->overview($domain->name_slug, $locationId, $period);

        try {
            $answer = $this->assistant->explain(
                $validated['topic'],
                $this->factSheet($domain, $overview),
                $validated['language'] ?? 'en',
                $validated['metric'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'text' => $answer['text'],
            'cached' => $answer['cached'],
            'disclaimer' => 'AI-generated explanation of your POS figures. Suggestions only, not professional financial advice.',
        ]);
    }

    /**
     * What Claude gets to see: the computed figures, the health check and the suggestions, with
     * customers' names left out (it only needs to know how the credit is spread, not who owes it).
     *
     * @param  array<string, mixed>  $overview
     * @return array<string, mixed>
     */
    private function factSheet(Domain $domain, array $overview): array
    {
        $receivables = $overview['receivables'];
        $receivables['largest_balances'] = array_map(
            fn ($row, $i) => Arr::except($row, ['customer_id', 'name']) + ['customer' => 'Customer '.chr(65 + $i)],
            $receivables['largest_balances'],
            array_keys($receivables['largest_balances']),
        );
        $receivables['overdue_customers'] = array_map(
            fn ($row) => Arr::only($row, ['overdue', 'days_overdue']),
            $receivables['overdue_customers'],
        );

        $period = $overview['period'];
        $locationId = $overview['location_id'];
        $start = Carbon::parse($period['start_date'])->startOfDay();
        $end = Carbon::parse($period['end_date'])->endOfDay();

        return [
            'business' => $domain->name,
            'currency' => 'PHP',
            'today' => today()->toDateString(),
            ...Arr::except($overview, ['receivables', 'location_id']),
            'receivables' => $receivables,
            'cash_flow' => Arr::except($this->reports->cashFlow($domain->name_slug, $locationId, $start, $end), ['money_in_by_method']),
            'balance_sheet' => Arr::except($this->reports->balanceSheet($domain->name_slug), ['cash_locations_counted']),
            'health_check' => array_map(fn ($h) => Arr::only($h, ['label', 'status', 'detail']), $this->insights->health($overview)),
            'suggestions' => array_map(fn ($r) => Arr::only($r, ['title', 'detail']), $this->insights->recommendations($overview)),
            'notes' => [
                'Revenue excludes VAT. Gross profit = revenue - cost of goods sold. Net profit = gross profit - operating expenses + other income - other expenses.',
                $overview['expenses_recorded']
                    ? 'Expenses are recorded by the owner; expenses billed by suppliers count on the bill date.'
                    : 'No expenses have been recorded in the system yet, so net profit equals gross profit and is overstated.',
                'Stock bought from suppliers is not an expense: it becomes cost of goods sold when sold.',
                'Customer credit and supplier bills cover the whole business, not one location.',
                'Inventory value, cash in drawer and the balance sheet are as of today, not the end of the period.',
                'Not recorded in the system yet: '.implode(', ', $overview['not_tracked']).'.',
            ],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: int|null}
     */
    private function resolveFilters(Request $request, Domain $domain): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(FinancialReportService::PERIODS)],
            'start_date' => ['nullable', 'required_if:period,custom', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $period = $this->reports->resolvePeriod(
            $validated['period'] ?? 'month',
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        $locationId = isset($validated['location_id']) ? (int) $validated['location_id'] : null;
        if ($locationId && ! InventoryLocation::query()->forDomain($domain->name_slug)->whereKey($locationId)->exists()) {
            $locationId = null;
        }

        return [$period, $locationId];
    }

    /** @return array<string, mixed> */
    private function sharedProps(Domain $domain, array $period, ?int $locationId): array
    {
        return [
            'filters' => [
                ...$this->reports->describePeriod($period),
                'location_id' => $locationId,
            ],
            'locations' => InventoryLocation::query()->forDomain($domain->name_slug)->active()->orderBy('name')->get(['id', 'name']),
            'aiEnabled' => $this->assistant->isConfigured(),
            'domainName' => $domain->name,
        ];
    }
}
