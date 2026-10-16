<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Ai\AiCreditService;
use App\Support\FreePlan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's AI usage: its plan, this month's AI credits and where they went. Credits
 * only; what the AI costs Techiko is for super admins (Domains/Show).
 */
class AiUsageController extends Controller
{
    public function __construct(
        private readonly AiCreditService $credits,
    ) {}

    public function index(Domain $domain): Response
    {
        $domain->loadMissing('currentServiceTier');
        $tier = $domain->currentServiceTier;

        return Inertia::render('AiUsage/Index', [
            'plan' => [
                'name' => $tier?->name ?? 'Free starter',
                'is_paid' => (bool) $domain->current_service_tier_id,
                'plan_credits' => $tier ? $tier->ai_monthly_credits : FreePlan::aiCredits(),
                'plan_unlimited' => $tier !== null && $tier->ai_monthly_credits === null,
            ],
            'aiCredits' => $this->credits->propsForFrontend($domain),
            'usage' => $this->credits->usageOverTime($domain),
            'byUser' => $this->credits->byUser($domain),
            'recent' => $this->credits->recent($domain),
        ]);
    }
}
