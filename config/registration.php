<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Registration route throttling (per client IP via throttle middleware)
    |--------------------------------------------------------------------------
    */
    'throttle' => [
        'page_per_minute' => (int) env('REGISTRATION_PAGE_THROTTLE_PER_MINUTE', 60),
        'submit_per_minute' => (int) env('REGISTRATION_SUBMIT_THROTTLE_PER_MINUTE', 10),
    ],
];
