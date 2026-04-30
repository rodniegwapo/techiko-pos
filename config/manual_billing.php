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
    | When false, only PayMongo QR Ph checkout is surfaced; billing.gcash.store
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

];
