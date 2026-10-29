<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Domain sidebar: Billing (/domains/{slug}/billing/servicing)
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

    /*
    |--------------------------------------------------------------------------
    | Unlimited product catalog (temporary bypass of tier / free-tier caps)
    |--------------------------------------------------------------------------
    | When true, effectiveMaxProducts is always unlimited and create limits
    | are not enforced. Set FEATURE_UNLIMITED_PRODUCTS=false to restore caps.
    */
    'unlimited_products' => filter_var(
        env('FEATURE_UNLIMITED_PRODUCTS', true),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Plan features (what the free plan leaves out)
    |--------------------------------------------------------------------------
    | When true, an organization only gets the features its plan includes
    | (service_tiers.features; see Domain::FEATURES): the free plan has 31 days
    | of sales history, 1 location and 2 users. When false, everything stays
    | open as before, except Finance and AI, which a super admin turns on.
    | Turn on together with the billing menu and the pricing page.
    */
    'plan_gating' => filter_var(
        env('FEATURE_PLAN_GATING', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Dine-in tables, tabs and kitchen tickets (coming soon)
    |--------------------------------------------------------------------------
    | When false, dine-in is off for every organization, VIP too (Domain::hasFeature):
    | the till shows no tables, the menu no Tables page, and its routes say
    | "coming soon". Stores' dine-in setting and their tables are kept.
    */
    'dine_in' => filter_var(
        env('FEATURE_DINE_IN', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Business types open for picking (the rest show "Coming soon")
    |--------------------------------------------------------------------------
    | A comma list of Domain::BUSINESS_TYPES, or "all". Retail is always open.
    | An organization whose saved type isn't open reads as retail meanwhile;
    | its saved type is kept and comes back when the type opens.
    */
    'business_types' => env('FEATURE_BUSINESS_TYPES', 'retail'),
];
