<?php

/*
| AI credits: each organization gets a monthly pool from its service tier
| (service_tiers.ai_monthly_credits, null = unlimited), shared by all its users.
| See App\Services\Ai\AiCreditService.
*/

return [
    // Organizations without a paid tier.
    'free_monthly_credits' => (int) env('AI_FREE_MONTHLY_CREDITS', 30),

    // Credits charged per successful AI answer, by feature.
    'credits' => [
        'assistant' => 1,
        'finance_question' => 2,
        'finance_explain' => 2,
    ],

    // The Billing page and chat drawer warn from this share of the month's credits.
    'warn_at_percent' => 80,

    // USD per 1M tokens, only for the internal cost estimate in ai_usages.cost_usd.
    // Check the provider's current pricing page when changing models.
    'prices' => [
        'gemini-3.8-flash' => ['input' => 0.30, 'output' => 2.50],
    ],
];
