<?php

namespace App\Services\Ai;

use App\Exceptions\OutOfAiCreditsException;
use App\Models\AiUsage;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Laravel\Ai\Responses\TextResponse;

/**
 * Monthly AI credits per organization: the pool comes from its service tier (free organizations get
 * ai_billing.free_monthly_credits), every user draws from it, and it resets on the 1st. What was
 * used is the sum of ai_usages this month, so there is no counter to drift.
 */
class AiCreditService
{
    /** Credits per month, or null when unlimited. A super admin's override beats the plan. */
    public function monthlyAllowance(Domain $domain): ?int
    {
        if ($domain->ai_monthly_credits_override !== null) {
            return $domain->ai_monthly_credits_override;
        }

        $domain->loadMissing('currentServiceTier');
        $tier = $domain->currentServiceTier;

        if (! $domain->current_service_tier_id || ! $tier) {
            return (int) config('ai_billing.free_monthly_credits', 30);
        }

        return $tier->ai_monthly_credits;
    }

    public function usedThisMonth(Domain $domain): int
    {
        return (int) AiUsage::query()
            ->where('domain', $domain->name_slug)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('credits');
    }

    /** Credits left this month, or null when unlimited. */
    public function remaining(Domain $domain): ?int
    {
        $allowance = $this->monthlyAllowance($domain);

        return $allowance === null ? null : max(0, $allowance - $this->usedThisMonth($domain));
    }

    public function creditsFor(string $feature): int
    {
        return max(0, (int) config('ai_billing.credits.'.$feature, 1));
    }

    /** Stops a request before the AI is called when the month's credits can't cover it. */
    public function assertHasCredits(Domain $domain, string $feature): void
    {
        $remaining = $this->remaining($domain);

        if ($remaining !== null && $remaining < $this->creditsFor($feature)) {
            $allowance = $this->monthlyAllowance($domain);

            throw new OutOfAiCreditsException(
                "Your organization has used all {$allowance} AI credits for ".now()->format('F').'. '
                .'They reset on '.now()->addMonthNoOverflow()->startOfMonth()->format('F j').', or contact us to change your plan.',
            );
        }
    }

    /** Charges one successful AI answer. A call that never reached the AI (no response) is free. */
    public function record(Domain $domain, ?User $user, string $feature, ?TextResponse $response): ?AiUsage
    {
        if ($response === null) {
            return null;
        }

        $usage = $response->usage;
        $model = $response->meta->model;
        $price = config('ai_billing.prices.'.$model);
        $cost = is_array($price)
            ? ($usage->inputTokens * (float) $price['input'] + $usage->outputTokens * (float) $price['output']) / 1_000_000
            : 0;

        return AiUsage::query()->create([
            'domain' => $domain->name_slug,
            'user_id' => $user?->id,
            'feature' => $feature,
            'provider' => $response->meta->provider,
            'model' => $model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cached_input_tokens' => $usage->cacheReadInputTokens,
            'credits' => $this->creditsFor($feature),
            'cost_usd' => $cost,
        ]);
    }

    /** Where the allowance comes from: a super admin's override, the paid plan, or the free plan. */
    public function allowanceSource(Domain $domain): string
    {
        if ($domain->ai_monthly_credits_override !== null) {
            return 'override';
        }

        return $domain->current_service_tier_id ? 'plan' : 'free';
    }

    /**
     * Credits per day (last 30 days), per week (last 12, starting Monday) and per month (last 12),
     * the current one included and periods without use as 0. Each `date` is the period's first day.
     *
     * @return array{daily: list<array{date: string, credits: int}>, weekly: list<array{date: string, credits: int}>, monthly: list<array{date: string, credits: int}>}
     */
    public function usageOverTime(Domain $domain): array
    {
        $days = today()->subDays(29);
        $weeks = today()->startOfWeek()->subWeeks(11);
        $months = today()->startOfMonth()->subMonths(11);

        $perDay = AiUsage::query()
            ->where('domain', $domain->name_slug)
            ->where('created_at', '>=', $days->min($weeks)->min($months))
            ->selectRaw('DATE(created_at) as day, SUM(credits) as credits')
            ->groupBy('day')
            ->pluck('credits', 'day');

        $series = function (Carbon $from, int $count, string $unit, string $startOf) use ($perDay): array {
            $totals = $perDay
                ->groupBy(fn ($credits, string $day) => Carbon::parse($day)->{$startOf}()->toDateString(), preserveKeys: true)
                ->map(fn ($days) => (int) $days->sum());

            return collect(range(0, $count - 1))
                ->map(function (int $i) use ($from, $unit, $totals) {
                    $date = $from->copy()->add($unit, $i)->toDateString();

                    return ['date' => $date, 'credits' => $totals[$date] ?? 0];
                })
                ->all();
        };

        return [
            'daily' => $series($days, 30, 'day', 'startOfDay'),
            'weekly' => $series($weeks, 12, 'week', 'startOfWeek'),
            'monthly' => $series($months, 12, 'month', 'startOfMonth'),
        ];
    }

    /**
     * This month's credits per user, most first. Calls without a user show as "System".
     *
     * @return list<array{user_id: ?int, name: string, credits: int, answers: int}>
     */
    public function byUser(Domain $domain): array
    {
        return AiUsage::query()
            ->where('ai_usages.domain', $domain->name_slug)
            ->where('ai_usages.created_at', '>=', now()->startOfMonth())
            ->leftJoin('users', 'users.id', '=', 'ai_usages.user_id')
            ->selectRaw('ai_usages.user_id, users.name, SUM(ai_usages.credits) as credits, COUNT(*) as answers')
            ->groupBy('ai_usages.user_id', 'users.name')
            ->orderByDesc('credits')
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'name' => $row->name ?? 'System',
                'credits' => (int) $row->credits,
                'answers' => (int) $row->answers,
            ])
            ->all();
    }

    /** The newest AI answers, without tokens or cost (those are for super admins). */
    public function recent(Domain $domain, int $perPage = 20): LengthAwarePaginator
    {
        return AiUsage::query()
            ->where('domain', $domain->name_slug)
            ->with('user:id,name')
            ->latest('id')
            ->paginate($perPage, ['id', 'user_id', 'feature', 'credits', 'created_at'])
            ->withQueryString()
            ->through(fn (AiUsage $usage) => [
                'id' => $usage->id,
                'at' => $usage->created_at?->toIso8601String(),
                'user' => $usage->user?->name ?? 'System',
                'feature' => $usage->feature,
                'credits' => $usage->credits,
            ]);
    }

    /** Estimated provider cost this month in USD. For super admins only, never shown to the organization. */
    public function costThisMonth(Domain $domain): float
    {
        return round((float) AiUsage::query()
            ->where('domain', $domain->name_slug)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd'), 4);
    }

    /** @return array<string, mixed> */
    public function propsForFrontend(Domain $domain): array
    {
        $allowance = $this->monthlyAllowance($domain);
        $used = $this->usedThisMonth($domain);
        $percent = $allowance ? min(100, (int) round($used / $allowance * 100)) : 0;

        $byFeature = AiUsage::query()
            ->where('domain', $domain->name_slug)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('feature, SUM(credits) as credits, COUNT(*) as answers')
            ->groupBy('feature')
            ->get()
            ->map(fn ($row) => ['feature' => $row->feature, 'credits' => (int) $row->credits, 'answers' => (int) $row->answers])
            ->values()
            ->all();

        return [
            'unlimited' => $allowance === null,
            'allowance' => $allowance,
            'used' => $used,
            'remaining' => $allowance === null ? null : max(0, $allowance - $used),
            'percent' => $percent,
            'near_limit' => $allowance !== null && $percent >= (int) config('ai_billing.warn_at_percent', 80),
            'resets_on' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
            'by_feature' => $byFeature,
            'credits_per_feature' => config('ai_billing.credits', []),
            'free_allowance' => (int) config('ai_billing.free_monthly_credits', 30),
            'source' => $this->allowanceSource($domain),
        ];
    }
}
