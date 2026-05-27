<?php

namespace Tests\Feature\Api;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DesktopAuthTokenApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{domain: Domain, user: User}
     */
    private function seedEligibleOrgUser(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Token API Org',
            'name_slug' => 'token-api-'.Str::lower(Str::random(6)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'location_id' => $location->id,
            'status' => 'active',
        ]);

        return compact('domain', 'user');
    }

    public function test_returns_bearer_token_for_eligible_domain_user_without_session_auth(): void
    {
        config(['nativephp-internal.running' => false]);

        $ctx = $this->seedEligibleOrgUser();

        $response = $this->postJson('/api/desktop/login', [
            'email' => $ctx['user']->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => ['id', 'name', 'email', 'domain'],
            ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertGuest();
    }

    public function test_super_user_rejected_under_native_when_requesting_api_token(): void
    {
        config(['nativephp-internal.running' => true]);

        $user = User::factory()->create([
            'is_super_user' => true,
        ]);

        $this->postJson('/api/desktop/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_invalid_credentials_return_validation_errors(): void
    {
        $this->postJson('/api/desktop/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
