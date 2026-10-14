<?php

namespace App\Http\Controllers\Domains\Finance;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Finance\MonthlyReview;
use App\Services\Ai\FinancialAssistant;
use App\Services\Finance\MonthlyReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * The monthly business reviews: written on the 1st of each month by `finance:monthly-review`,
 * or on demand here when the scheduler isn't running.
 */
class MonthlyReviewController extends Controller
{
    public function __construct(private readonly MonthlyReviewService $reviews) {}

    public function index(Request $request, Domain $domain)
    {
        $slug = $domain->name_slug;
        $all = MonthlyReview::query()->forDomain($slug)->orderByDesc('month')->get();

        $selected = $request->query('month')
            ? $all->firstWhere('month', $request->query('month'))
            : $all->first();
        if ($selected && $selected->read_at === null) {
            $selected->update(['read_at' => now()]);
        }

        $lastMonth = now()->subMonthNoOverflow()->format('Y-m');

        return Inertia::render('Finance/MonthlyReviews', [
            'reviews' => $all->map->only(['id', 'month', 'label', 'read_at', 'generated_at'])->values(),
            'selected' => $selected,
            'lastMonth' => $lastMonth,
            'lastMonthLabel' => now()->subMonthNoOverflow()->format('F Y'),
            'lastMonthMissing' => ! $all->contains('month', $lastMonth),
            'aiEnabled' => app(FinancialAssistant::class)->isConfigured(),
            'domainName' => $domain->name,
        ]);
    }

    public function generate(Request $request, Domain $domain): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        try {
            $review = $this->reviews->generate($domain, $data['month']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }

        return redirect()
            ->route('domains.finance.reviews.index', ['domain' => $domain->name_slug, 'month' => $review->month])
            ->with('success', 'Review for '.$review->label.' is ready.');
    }
}
