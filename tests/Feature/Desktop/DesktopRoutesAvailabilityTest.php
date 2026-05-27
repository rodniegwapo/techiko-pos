<?php

namespace Tests\Feature\Desktop;

use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Routes in routes/desktop.php are guarded unless NativePHP, TEST_NATIVE_DESKTOP_ROUTES at bootstrap,
 * or APP_ENV=local (this suite runs under APP_ENV=testing so paths stay blocked).
 */
class DesktopRoutesAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['nativephp-internal.running' => false]);
        putenv('TEST_NATIVE_DESKTOP_ROUTES=false');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('TEST_NATIVE_DESKTOP_ROUTES');
        unset($_ENV['TEST_NATIVE_DESKTOP_ROUTES'], $_SERVER['TEST_NATIVE_DESKTOP_ROUTES']);
    }

    public function test_guest_gets_not_found_when_desktop_routes_are_not_enabled(): void
    {
        $this->withoutVite();

        $this->get('/desktop/login')->assertStatus(Response::HTTP_NOT_FOUND);
    }
}
