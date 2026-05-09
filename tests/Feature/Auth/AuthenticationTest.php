<?php

namespace Tests\Feature\Auth;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $domain = Domain::create([
            'name' => 'Login Test Org',
            'timezone' => 'Asia/Manila',
            'country_code' => 'PH',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'status' => 'active',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(
            route('domains.sales.index', ['domain' => $user->domain]),
        );
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_unverified_users_can_login_but_redirected_from_verified_routes(): void
    {
        $domain = Domain::create([
            'name' => 'Unverified Org Test',
            'timezone' => 'Asia/Manila',
            'country_code' => 'PH',
            'is_active' => true,
        ]);

        $user = User::factory()->unverified()->create([
            'domain' => $domain->name_slug,
            'status' => 'active',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        $this->get('/dashboard')->assertRedirect(route('verification.notice'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => $user->email,
            'email_verified_at' => null,
        ]);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
