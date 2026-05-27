<?php

namespace Tests\Unit;

use App\Services\Database\OnlineReachabilityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnlineReachabilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_probe_reports_online_when_http_succeeds(): void
    {
        Config::set('runtime_database.health_check_url', 'https://example.test/ping');

        Http::fake([
            'https://example.test/ping' => Http::response('ok', 200),
        ]);

        $service = new OnlineReachabilityService;

        $this->assertTrue($service->isOnline());

        Http::assertSent(fn ($request) => $request->url() === 'https://example.test/ping');
    }

    public function test_probe_reports_offline_on_transport_failure(): void
    {
        Config::set('runtime_database.health_check_url', 'https://example.test/ping');

        Http::fake([
            'https://example.test/ping' => function () {
                throw new \RuntimeException('broken');
            },
        ]);

        $service = new OnlineReachabilityService;

        $this->assertFalse($service->isOnline());
    }

    public function test_resolved_probe_url_uses_health_relative_to_app_url_when_unset(): void
    {
        Config::set('app.url', 'https://shop.example');

        Config::set('runtime_database.health_check_url', null);

        $service = new OnlineReachabilityService;

        $this->assertSame('https://shop.example/health', $service->resolvedProbeUrl());
    }
}
