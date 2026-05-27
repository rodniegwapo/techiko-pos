<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep generated URLs (Ziggy/Inertia) on the same scheme+host as the incoming request so
 * session cookies (host-only) are sent on follow-up POSTs. Fixes 419 when APP_URL
 * (e.g. techiko-pos.test) differs from the embedded server (e.g. http://127.0.0.1:...).
 */
class AlignApplicationUrlWithRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningInConsole()) {
            return $next($request);
        }

        $host = $request->getHost();

        if ($host === '' || $host === '0.0.0.0') {
            return $next($request);
        }

        $root = $request->getSchemeAndHttpHost();
        if ($root === '') {
            return $next($request);
        }

        URL::forceRootUrl($root);
        Config::set('app.url', $root);

        return $next($request);
    }
}
