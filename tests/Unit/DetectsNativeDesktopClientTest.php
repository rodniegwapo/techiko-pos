<?php

namespace Tests\Unit;

use App\Support\DetectsNativeDesktopClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DetectsNativeDesktopClientTest extends TestCase
{
    public function test_matches_when_user_agent_contains_configured_substring(): void
    {
        Config::set('app.force_native_desktop_client', false);
        Config::set('app.native_desktop_ua_match', 'Electron');

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120.0.0.0 Electron/28.0.0',
        ]);

        $this->assertTrue(DetectsNativeDesktopClient::matches($request));
    }

    public function test_does_not_match_normal_browser_user_agent(): void
    {
        Config::set('app.force_native_desktop_client', false);
        Config::set('app.native_desktop_ua_match', 'Electron');

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120.0.0.0 Safari/537.36',
        ]);

        $this->assertFalse(DetectsNativeDesktopClient::matches($request));
    }

    public function test_force_native_desktop_short_circuits_without_electron_ua(): void
    {
        Config::set('app.force_native_desktop_client', true);
        Config::set('app.native_desktop_ua_match', 'Electron');

        $request = Request::create('/');

        $this->assertTrue(DetectsNativeDesktopClient::matches($request));
    }
}
