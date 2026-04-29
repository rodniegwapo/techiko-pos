<?php

namespace Tests\Feature\Billing;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use Database\Seeders\PermissionModuleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PayMongoSubscribeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionModuleSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->withoutMiddleware([
            RoleBasedAccessControl::class,
        ]);
    }

    /**
     * @return array{domain: Domain, user: User}
     */
    private function seedUserInDomain(string $role, int $roleLevel): array
    {
        $domain = Domain::query()->create([
            'name' => 'Test Org',
            'name_slug' => 'test-org-'.Str::lower(Str::random(8)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'role_level' => $roleLevel,
            'location_id' => $location->id,
        ]);
        $user->assignRole($role);

        return compact('domain', 'user');
    }

    public function test_admin_post_subscribe_passes_permission_middleware(): void
    {
        $ctx = $this->seedUserInDomain('admin', 2);
        $url = route('domains.billing.paymongo.subscribe', ['domain' => $ctx['domain']->name_slug]);

        $response = $this->actingAs($ctx['user'])->postJson($url);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_manager_post_subscribe_is_forbidden_without_billing_permission(): void
    {
        $ctx = $this->seedUserInDomain('manager', 3);
        $url = route('domains.billing.paymongo.subscribe', ['domain' => $ctx['domain']->name_slug]);

        $response = $this->actingAs($ctx['user'])->postJson($url);

        $response->assertForbidden();
    }
}
