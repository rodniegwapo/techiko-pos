<?php

namespace Tests\Feature\Auth;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NativeDesktopLoginRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nativephp-internal.running' => true]);
    }

    protected function tearDown(): void
    {
        config(['nativephp-internal.running' => false]);

        parent::tearDown();
    }

    public function test_get_desktop_login_renders(): void
    {
        $this->withoutVite();

        $this->get('/desktop/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Desktop/DesktopLogin'));
    }

    public function test_super_user_cannot_complete_desktop_login_on_native(): void
    {
        $user = User::factory()->create([
            'is_super_user' => true,
        ]);

        $response = $this->post('/desktop/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
        $response->assertSessionHas('errors');
    }

    public function test_super_user_desktop_login_json_returns_validation_error(): void
    {
        $user = User::factory()->create([
            'is_super_user' => true,
        ]);

        $response = $this->postJson('/desktop/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * @return array{domain: Domain, user: User}
     */
    private function seedDomainUserWithDashboardPermission(): array
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $domain = Domain::query()->create([
            'name' => 'Native Test Org',
            'name_slug' => 'native-test-'.Str::lower(Str::random(6)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $permission = Permission::query()->create([
            'name' => 'Test dashboard native '.Str::uuid(),
            'guard_name' => 'web',
            'route_name' => 'dashboard',
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'location_id' => $location->id,
            'status' => 'active',
        ]);

        $user->givePermissionTo($permission);

        return compact('domain', 'user');
    }

    public function test_domain_user_desktop_login_redirects_to_organization_dashboard_on_native(): void
    {
        $ctx = $this->seedDomainUserWithDashboardPermission();

        $response = $this->post('/desktop/login', [
            'email' => $ctx['user']->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('domains.dashboard', ['domain' => $ctx['domain']->name_slug]));
    }

    public function test_domain_user_desktop_login_json_returns_redirect_url(): void
    {
        $ctx = $this->seedDomainUserWithDashboardPermission();

        $response = $this->postJson('/desktop/login', [
            'email' => $ctx['user']->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $expected = route('domains.dashboard', ['domain' => $ctx['domain']->name_slug]);

        $response->assertOk();
        $response->assertJson([
            'redirect' => $expected,
        ]);
    }

    public function test_global_dashboard_redirects_to_organization_dashboard_on_native(): void
    {
        $ctx = $this->seedDomainUserWithDashboardPermission();

        $response = $this->actingAs($ctx['user'])->get('/dashboard');

        $response->assertRedirect(route('domains.dashboard', ['domain' => $ctx['domain']->name_slug]));
    }

    public function test_global_dashboard_forbidden_for_super_user_on_native(): void
    {
        $user = User::factory()->create([
            'is_super_user' => true,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }

    public function test_super_user_can_login_via_web_when_not_native(): void
    {
        config(['nativephp-internal.running' => false]);

        $user = User::factory()->create([
            'is_super_user' => true,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }
}
