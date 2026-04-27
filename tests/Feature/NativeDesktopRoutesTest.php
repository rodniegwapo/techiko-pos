<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NativeDesktopRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Enable the same route table as NativePHP desktop without NATIVEPHP_RUNNING
     * (which would switch DB to the nativephp connection during tests).
     */
    public function createApplication(): Application
    {
        putenv('TEST_NATIVE_DESKTOP_ROUTES=1');
        $_ENV['TEST_NATIVE_DESKTOP_ROUTES'] = '1';

        $app = require __DIR__.'/../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        putenv('TEST_NATIVE_DESKTOP_ROUTES');
        unset($_ENV['TEST_NATIVE_DESKTOP_ROUTES'], $_SERVER['TEST_NATIVE_DESKTOP_ROUTES']);

        parent::tearDown();
    }

    public function test_root_redirects_guest_to_login(): void
    {
        $this->get('/')
            ->assertRedirect(route('login'));
    }

    public function test_marketing_paths_are_not_registered(): void
    {
        foreach (['/services', '/about', '/contact', '/pricing'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_root_redirects_authenticated_user_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }
}
