<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionExpiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CSRF checks are skipped in tests, so simulate an expired token directly.
        Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException);
    }

    public function test_guest_with_expired_session_is_redirected_to_login(): void
    {
        $this->post('/_test/expired')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your session expired. Please log in again.');
    }

    public function test_inertia_request_with_expired_session_is_redirected_to_login(): void
    {
        $this->post('/_test/expired', [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_with_expired_token_goes_back_with_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/sales')
            ->post('/_test/expired')
            ->assertRedirect('/sales')
            ->assertSessionHas('error');
    }

    public function test_json_request_still_gets_419(): void
    {
        $this->postJson('/_test/expired')->assertStatus(419);
    }
}
