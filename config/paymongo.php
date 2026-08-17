<?php

return [

    'api_base' => rtrim((string) env('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1'), '/'),

    'secret_key' => env('PAYMONGO_SECRET_KEY'),

    'public_key' => env('PAYMONGO_PUBLIC_KEY'),

    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),

    /**
     * Documentary: URL pasted in PayMongo Dashboard (tunnel / production).
     */
    'webhook_url_documentation' => env('PAYMONGO_WEBHOOK_URL'),

];
