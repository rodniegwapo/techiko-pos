<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Finance\MonthlyReview;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\MonthlyReviewReady;
use App\Services\Finance\MonthlyReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Writes last month's business review for every business that sold something that month.
 * Scheduled for the 1st of each month; the Monthly reviews page can also generate one on demand.
 */
class GenerateMonthlyReviews extends Command
{
    protected $signature = 'finance:monthly-review
        {--month= : Month to review (YYYY-MM); last month by default}
        {--domain= : Only this business (its slug)}
        {--force : Rebuild reviews that already exist}
        {--no-email : Don\'t email owners and managers}';

    protected $description = 'Generate the monthly business review for last month';

    public function handle(MonthlyReviewService $reviews): int
    {
        $month = $this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Month must look like 2026-09.');

            return self::FAILURE;
        }

        $start = $month.'-01';
        $domains = Domain::query()
            ->when($this->option('domain'), fn ($q, $slug) => $q->where('name_slug', $slug))
            ->whereIn('name_slug', Sale::query()
                ->where('payment_status', 'paid')
                ->whereBetween('transaction_date', [$start, date('Y-m-t 23:59:59', strtotime($start))])
                ->select('domain'))
            ->get();

        foreach ($domains as $domain) {
            $exists = MonthlyReview::query()->forDomain($domain->name_slug)->where('month', $month)->exists();
            if ($exists && ! $this->option('force')) {
                $this->line("{$domain->name}: already reviewed");

                continue;
            }

            try {
                $review = $reviews->generate($domain, $month);
                $this->info("{$domain->name}: review for {$month} ready");

                if (! $this->option('no-email')) {
                    $sent = $this->notifyReaders($domain, $review);
                    $this->line("  emailed {$sent} owner(s)/manager(s)");
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("{$domain->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /** Emails everyone in the business who may read the reviews (owners/admins and managers). */
    private function notifyReaders(Domain $domain, MonthlyReview $review): int
    {
        $readers = User::query()
            ->where('domain', $domain->name_slug)
            ->whereNotNull('email')
            ->whereNotNull('email_verified_at')
            ->get()
            ->filter(fn (User $user) => $user->is_super_user
                || $user->getAllPermissions()->contains('route_name', 'finance.reviews.index'));

        Notification::send($readers, new MonthlyReviewReady($review, $domain));

        return $readers->count();
    }
}
