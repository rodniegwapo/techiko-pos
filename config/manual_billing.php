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
    | Only read once, by the migration that added service_tiers.shown_on_billing; after that,
    | super admins show or hide plans on the Plans page.
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
            '✅ Everything in Free',
            '🧾 Full sales history with CSV export',
            '👥 More users (see the limit above)',
        ],
        'standard' => [
            '✅ Everything in Basic',
            '🏪 Multi-store: more stores and warehouses',
            '🔄 Stock transfers, movements and valuation',
            '🧾 Expenses (recurring too)',
        ],
        'premium' => [
            '✅ Everything in Standard',
            '📊 Finance: P&L, cash flow, balance sheet, VAT report',
            '📒 Supplier bills, receivables, loans and assets',
            '✨ AI assistant and monthly business reviews',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Free tier marketing (shown on servicing billing — non-selectable card)
    |--------------------------------------------------------------------------
    */
    'free_tier_marketing_bullets' => [
        'Point of sale, offline selling and receipts',
        'Unlimited products, discounts, customers and loyalty',
        'Stock tracking, adjustments and low-stock alerts',
        'Utang and cash drawer',
        '1 location, 2 users, last 31 days of sales history',
    ],

];
