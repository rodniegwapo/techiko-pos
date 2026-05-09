<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApiInventoryAndUsersRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_user_can_get_api_inventory_products(): void
    {
        $user = User::factory()->create([
            'is_super_user' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/inventory/products');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['success', 'data', 'pagination']);
    }

    public function test_user_with_inventory_products_permission_can_get_api_inventory_products(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $user = User::factory()->create([
            'is_super_user' => false,
        ]);

        $permission = Permission::query()->create([
            'name' => 'Test inventory.products '.Str::uuid(),
            'guard_name' => 'web',
            'route_name' => 'inventory.products',
        ]);

        $user->givePermissionTo($permission);

        $response = $this->actingAs($user)->getJson('/api/inventory/products');

        $response->assertOk();
    }

    public function test_user_without_inventory_products_permission_gets_403_on_api_inventory_products(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $user = User::factory()->create([
            'is_super_user' => false,
        ]);

        $response = $this->actingAs($user)->getJson('/api/inventory/products');

        $response->assertForbidden();
        $response->assertJsonPath('error', 'Forbidden');
    }

    public function test_super_user_can_get_api_users_index(): void
    {
        $user = User::factory()->create([
            'is_super_user' => true,
        ]);

        $response = $this->actingAs($user)->get('/api/users');

        $response->assertOk();
    }

    public function test_user_with_users_index_permission_can_get_api_users_list(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $user = User::factory()->create([
            'is_super_user' => false,
        ]);

        $permission = Permission::query()->create([
            'name' => 'Test users.index '.Str::uuid(),
            'guard_name' => 'web',
            'route_name' => 'users.index',
        ]);

        $user->givePermissionTo($permission);

        $response = $this->actingAs($user)->get('/api/users');

        $response->assertOk();
    }

    public function test_user_without_users_index_permission_gets_403_on_api_users(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $user = User::factory()->create([
            'is_super_user' => false,
        ]);

        $response = $this->actingAs($user)->getJson('/api/users');

        $response->assertForbidden();
        $response->assertJsonPath('error', 'Forbidden');
    }
}
