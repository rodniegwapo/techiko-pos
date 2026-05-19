<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Canonical base URL for SEO (sitemap, robots Sitemap: line)
    |--------------------------------------------------------------------------
    |
    | When set, overrides config('app.url') for SeoController only.
    | Use when APP_URL differs from the public HTTPS host.
    |
    */

    'canonical_url' => env('APP_CANONICAL_URL'),

];
