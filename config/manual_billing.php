<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public path to GCash QR image (under /public)
    |--------------------------------------------------------------------------
    */
    'gcash_qr_path' => env('MANUAL_BILLING_GCASH_QR_PATH', '/images/gcash-qr.svg'),

    'currency_code' => 'PHP',

    'currency_symbol' => '₱',

    /*
    |--------------------------------------------------------------------------
    | Show legacy GCash reference flow on servicing payment page (UI + POST accepted)
    |--------------------------------------------------------------------------
    |
    | When false, only PayMongo QR Ph checkout is surfaced; billing.servicing.manual_gcash
    | returns HTTP 403 to prevent bypass via crafted requests.
    |
    */
    'show_manual_gcash_section' => env('MANUAL_BILLING_SHOW_MANUAL_GCASH', false),

    /*
    |--------------------------------------------------------------------------
    | Basic tier bundled QR Ph (Vue @assets, no PayMongo PaymentIntent)
    |--------------------------------------------------------------------------
    |
    | service_tiers.slug matched case-insensitively; that tier gets a fixed
    | qr image in Billing/Gcash instead of Generate QR Ph API checkout.
    |
    */
    'servicing_basic_tier_slug' => env('SERVICING_BASIC_TIER_SLUG', 'basic'),

    /*
    |--------------------------------------------------------------------------
    | Tiers excluded from Billing/Gcash picker (slugs vs service_tiers.slug)
    |--------------------------------------------------------------------------
    |
    | Still active elsewhere; omit from servicing payment UI until you are ready.
    |
    */
    'billing_hidden_tier_slugs' => [
        'premium',
    ],

    /*
    |--------------------------------------------------------------------------
    | Servicing tier marketing bullets (shown on Billing/Gcash)
    |--------------------------------------------------------------------------
    |
    | Keys match service_tiers.slug so copy stays centralized here.
    |
    */
    'tier_marketing_bullets' => [
        'basic' => [
            '👥 Multi-user (cashier accounts)',
            '💰 Cash control: cash in/out; expected vs actual',
            '📊 Simple analytics: top products; daily trends',
            '📒 Advanced utang: due dates; payment history',
        ],
        'standard' => [
            '🏪 Multi-store',
            '🔄 Stock transfers',
            '📦 Supplier tracking',
            '📈 Advanced reports',
            '🔔 Smart alerts (utang reminders, low stock)',
        ],
        'premium' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Free tier marketing (shown on servicing billing — non-selectable card)
    |--------------------------------------------------------------------------
    */
    'free_tier_marketing_bullets' => [
        'Point-of-sale essentials for day-to-day selling',
        'Basic inventory and product catalog',
        'Utang (basic) and wallet (trial-period access)',
        'Upgrade below for unlimited products and full servicing tiers',
    ],

];
