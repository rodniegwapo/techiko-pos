<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OwnerSelfRoleLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * @return array{domain: Domain, adminRole: Role, managerRole: Role, cashierRole: Role, admin: User, other: User}
     */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Owner Lock Org',
            'name_slug' => 'owner-lock-'.Str::lower(Str::random(8)),
        ]);

        $adminRole = Role::findOrCreate('admin', 'web');
        $adminRole->update(['level' => 2]);

        $managerRole = Role::findOrCreate('manager', 'web');
        $managerRole->update(['level' => 3]);

        $cashierRole = Role::findOrCreate('cashier', 'web');
        $cashierRole->update(['level' => 5]);

        $admin = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'name' => 'Domain Admin',
            'email' => 'admin-'.Str::lower(Str::random(6)).'@example.com',
        ]);
        $admin->assignRole($adminRole);

        $other = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'name' => 'Store Cashier',
            'email' => 'cashier-'.Str::lower(Str::random(6)).'@example.com',
            'supervisor_id' => $admin->id,
        ]);
        $other->assignRole($cashierRole);

        return [
            'domain' => $domain,
            'adminRole' => $adminRole,
            'managerRole' => $managerRole,
            'cashierRole' => $cashierRole,
            'admin' => $admin,
            'other' => $other,
        ];
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['admin'])->putJson(
            "/domains/{$ctx['domain']->name_slug}/users/{$ctx['admin']->id}",
            [
                'name' => $ctx['admin']->name,
                'email' => $ctx['admin']->email,
                'role_id' => $ctx['managerRole']->id,
            ]
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['role_id']);

        $this->assertTrue(
            $ctx['admin']->fresh()->hasRole('admin'),
            'Admin should keep the admin role'
        );
        $this->assertFalse($ctx['admin']->fresh()->hasRole('manager'));
    }

    public function test_admin_can_update_self_without_role_id(): void
    {
        $ctx = $this->seedContext();
        $newName = 'Updated Admin Name';

        $response = $this->actingAs($ctx['admin'])->putJson(
            "/domains/{$ctx['domain']->name_slug}/users/{$ctx['admin']->id}",
            [
                'name' => $newName,
                'email' => $ctx['admin']->email,
            ]
        );

        $response->assertOk();
        $this->assertSame($newName, $ctx['admin']->fresh()->name);
        $this->assertTrue($ctx['admin']->fresh()->hasRole('admin'));
    }

    public function test_admin_can_change_another_users_role(): void
    {
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['admin'])->putJson(
            "/domains/{$ctx['domain']->name_slug}/users/{$ctx['other']->id}",
            [
                'name' => $ctx['other']->name,
                'email' => $ctx['other']->email,
                'role_id' => $ctx['managerRole']->id,
                'supervisor_id' => $ctx['admin']->id,
            ]
        );

        $response->assertOk();
        $this->assertTrue($ctx['other']->fresh()->hasRole('manager'));
        $this->assertFalse($ctx['other']->fresh()->hasRole('cashier'));
    }
}
