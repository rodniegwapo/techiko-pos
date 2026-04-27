<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Test-only: native desktop route table
    |--------------------------------------------------------------------------
    |
    | When set (only from NativeDesktopRoutesTest before the app boots), routes
    | match the NativePHP desktop branch without setting NATIVEPHP_RUNNING,
    | which would otherwise enable NativePHP test database behavior.
    |
    */
    'test_native_desktop_routes' => filter_var(env('TEST_NATIVE_DESKTOP_ROUTES', false), FILTER_VALIDATE_BOOLEAN),

];
