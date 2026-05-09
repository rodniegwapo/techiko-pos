<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Domain sidebar: Servicing payment (/domains/{slug}/billing/servicing)
    |--------------------------------------------------------------------------
    | When false, the menu entry is hidden; routes and pages stay registered.
    */
    'domain_servicing_sidebar_visible' => filter_var(
        env('FEATURE_DOMAIN_SERVICING_SIDEBAR_VISIBLE', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Marketing site: Pricing page and nav / CTA links
    |--------------------------------------------------------------------------
    | When false, public pricing nav and CTAs are hidden; /pricing still works.
    */
    'marketing_pricing_visible' => filter_var(
        env('FEATURE_MARKETING_PRICING_VISIBLE', false),
        FILTER_VALIDATE_BOOLEAN
    ),
];
