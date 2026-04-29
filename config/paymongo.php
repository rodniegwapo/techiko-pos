<?php

return [

    'secret_key' => env('PAYMONGO_SECRET_KEY'),

    'public_key' => env('PAYMONGO_PUBLIC_KEY'),

    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),

    /** Registered webhook endpoint (informational; PayMongo dashboard must match). */
    'webhook_url' => env('PAYMONGO_WEBHOOK_URL'),

    /** Plan ID from PayMongo dashboard (test or live). Required to start a subscription. */
    'plan_id' => env('PAYMONGO_PLAN_ID'),

    'api_base' => 'https://api.paymongo.com',

];
