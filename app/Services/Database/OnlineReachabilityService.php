<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OnlineReachabilityService
{
    private const CACHE_KEY_PREFIX = 'runtime_db:reachable:';

    public function isOnline(): bool
    {
        $forced = $this->resolvedForceState();

        if ($forced !== null) {
            return $forced;
        }

        $ttl = (int) config('runtime_database.cache_ttl_seconds');

        return (bool) Cache::remember($this->cacheKey(), max(1, $ttl), function () {
            return $this->probeHealthEndpoint();
        });
    }

    private function resolvedForceState(): ?bool
    {
        if (! config('runtime_database.force_flags_allowed')) {
            return null;
        }

        $forceOnline = filter_var(env('RUNTIME_DB_FORCE_ONLINE', false), FILTER_VALIDATE_BOOLEAN);
        $forceOffline = filter_var(env('RUNTIME_DB_FORCE_OFFLINE', false), FILTER_VALIDATE_BOOLEAN);

        if ($forceOnline) {
            return true;
        }

        if ($forceOffline) {
            return false;
        }

        return null;
    }

    private function probeHealthEndpoint(): bool
    {
        $url = $this->resolvedProbeUrl();

        $timeoutSeconds = max(0.1, ((int) config('runtime_database.timeout_ms')) / 1000);

        try {
            $response = Http::timeout($timeoutSeconds)
                ->connectTimeout($timeoutSeconds)
                ->withHeaders([
                    'User-Agent' => 'TechikoPOS-RuntimeDbProbe/1.0',
                    'Accept' => '*/*',
                ])
                ->get($url);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function resolvedProbeUrl(): string
    {
        $configured = trim((string) config('runtime_database.health_check_url'));
        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/health';
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX.sha1($this->resolvedProbeUrl());
    }
}
