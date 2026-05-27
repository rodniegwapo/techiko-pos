<?php

namespace Tests\Feature\RuntimeDatabase;

use App\Services\Database\OnlineReachabilityService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RuntimeDatabaseConnectionTest extends TestCase
{
    public function test_switch_disabled_does_not_change_default_connection_after_web_request(): void
    {
        Config::set('runtime_database.enabled', false);

        $expected = env('DB_CONNECTION', 'mysql');

        $response = $this->get(route('health'));

        $response->assertOk();
        $this->assertSame($expected, DB::getDefaultConnection());
    }

    public function test_health_route_is_reachable_under_web_middleware(): void
    {
        $response = $this->get(route('health'));

        $response->assertOk();
        $response->assertSee('OK');
    }

    public function test_switch_online_uses_mysql_default(): void
    {
        Config::set('runtime_database.enabled', true);

        $this->mock(OnlineReachabilityService::class, fn ($m) => $m->allows('isOnline')->andReturn(true));

        $this->get('/login');

        $this->assertSame('mysql', DB::getDefaultConnection());
    }

    public function test_switch_offline_uses_offline_sqlite_default(): void
    {
        Config::set('runtime_database.enabled', true);

        $this->mock(OnlineReachabilityService::class, fn ($m) => $m->allows('isOnline')->andReturn(false));

        $this->get('/login');

        $this->assertSame('offline_sqlite', DB::getDefaultConnection());
    }
}
