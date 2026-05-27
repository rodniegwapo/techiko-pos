<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Required for Axios `X-XSRF-TOKEN` header (plaintext cookie Laravel sets for CSRF)
        'XSRF-TOKEN',
    ];
}
