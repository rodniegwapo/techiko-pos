<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Runtime DB switch (MySQL ↔ offline SQLite)
    |--------------------------------------------------------------------------
    |
    | When enabled, SelectRuntimeDatabaseConnection middleware sets Laravel's
    | default DB connection per request based on cached HTTP reachability probe.
    | See README "Offline SQLite" section for migrate commands and caveats.
    |
    */

    'enabled' => filter_var(env('RUNTIME_DB_SWITCH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'online_connection' => env('RUNTIME_DB_ONLINE_CONNECTION', 'mysql'),

    'offline_connection' => env('RUNTIME_DB_OFFLINE_CONNECTION', 'offline_sqlite'),

    /*
    | When null, probes GET {APP_URL}/health on the running app’s public URL.
    | Point this at an external ping URL if probing the same PHP process must be avoided.
    */
    'health_check_url' => env('ONLINE_HEALTHCHECK_URL'),

    'timeout_ms' => max(50, (int) env('ONLINE_HEALTHCHECK_TIMEOUT_MS', 2000)),

    'cache_ttl_seconds' => max(1, (int) env('ONLINE_DB_CACHE_TTL_SECONDS', 45)),

    /*
    | Force flags are honoured only when this is true. Normal use: APP_DEBUG or local env.
    | Set RUNTIME_DB_ALLOW_FORCE_FLAGS=true in PHPUnit or CI where you rely on overrides.
    */
    'force_flags_allowed' => filter_var(env('RUNTIME_DB_ALLOW_FORCE_FLAGS', false), FILTER_VALIDATE_BOOLEAN)
        || filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)
        || env('APP_ENV') === 'local',

];
