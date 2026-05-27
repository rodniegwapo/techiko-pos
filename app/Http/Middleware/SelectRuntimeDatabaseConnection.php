<?php

namespace App\Http\Middleware;

use App\Services\Database\OnlineReachabilityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SelectRuntimeDatabaseConnection
{
    public const ATTRIBUTE_DB_MODE = 'runtime_db.mode';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('runtime_database.enabled')) {
            $request->attributes->set(self::ATTRIBUTE_DB_MODE, 'online');

            return $next($request);
        }

        if ($this->shouldSkipSelection($request)) {
            return $next($request);
        }

        $online = app(OnlineReachabilityService::class)->isOnline();

        $onlineConn = config('runtime_database.online_connection');
        $offlineConn = config('runtime_database.offline_connection');

        $target = $online ? $onlineConn : $offlineConn;

        Config::set('database.default', $target);

        foreach ([$onlineConn, $offlineConn] as $name) {
            DB::purge($name);
        }

        DB::reconnect($target);

        $request->attributes->set(self::ATTRIBUTE_DB_MODE, $online ? 'online' : 'offline');

        return $next($request);
    }

    /**
     * Inbound probes to `/health` must not fan out recursive HTTP probes in the same app.
     */
    private function shouldSkipSelection(Request $request): bool
    {
        if ($request->routeIs('health')) {
            return true;
        }

        return false;
    }
}
