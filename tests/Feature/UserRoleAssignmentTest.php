<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "No role assignment on user": the role list and the save follow one rule, a manager can change
 * the roles of people below them, nothing is dropped silently, and users.role_level follows the role.
 */
class UserRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    /** @var array<string, Role> */
    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);

        $this->domain = Domain::query()->create(['name' => 'Roles Org', 'name_slug' => 'roles-org-'.Str::lower(Str::random(6))]);
        foreach (['super admin' => 1, 'admin' => 2, 'manager' => 3, 'supervisor' => 4, 'cashier' => 5, 'stock clerk' => 6] as $name => $level) {
            $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->forceFill(['level' => $level])->save();
            $this->roles[$name] = $role;
        }
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false]);
        $user->assignRole($this->roles[$role]);

        return $user;
    }

    private function create(User $actor, string $role)
    {
        return $this->actingAs($actor)->postJson(route('domains.users.store', ['domain' => $this->domain->name_slug]), [
            'name' => 'New '.$role, 'email' => Str::random(8).'@example.test',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role_id' => $this->roles[$role]->id,
        ]);
    }

    public function test_the_role_list_matches_what_each_person_may_assign(): void
    {
        $names = function (User $actor) {
            $names = [];
            $this->actingAs($actor)->get(route('domains.users.index', ['domain' => $this->domain->name_slug]))
                ->assertOk()
                ->assertInertia(function (Assert $page) use (&$names) {
                    $names = collect($page->toArray()['props']['roles'])->pluck('name')->all();
                });

            return $names;
        };

        $this->assertSame(['admin', 'manager', 'supervisor', 'cashier', 'stock clerk'], $names($this->member('admin')));
        $this->assertSame(['supervisor', 'cashier', 'stock clerk'], $names($this->member('manager')));
    }

    public function test_an_admin_creates_admins_and_staff_and_their_level_follows_the_role(): void
    {
        $admin = $this->member('admin');

        foreach (['admin' => 2, 'manager' => 3, 'cashier' => 5] as $role => $level) {
            $this->create($admin, $role)->assertCreated();
            $created = User::query()->where('name', 'New '.$role)->firstOrFail();
            $this->assertTrue($created->hasRole($role));
            $this->assertSame($level, (int) $created->role_level);
        }

        $this->create($admin, 'super admin')->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_a_manager_assigns_roles_below_them_only(): void
    {
        $manager = $this->member('manager');

        $this->create($manager, 'stock clerk')->assertCreated();
        $this->create($manager, 'admin')->assertStatus(422)->assertJsonValidationErrors('role_id');
        $this->create($manager, 'manager')->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_a_manager_changes_a_cashiers_role_and_it_is_saved(): void
    {
        $manager = $this->member('manager');
        $cashier = $this->member('cashier');

        $this->actingAs($manager)->putJson(route('domains.users.update', ['domain' => $this->domain->name_slug, 'user' => $cashier->id]), [
            'name' => $cashier->name, 'email' => $cashier->email, 'role_id' => $this->roles['supervisor']->id,
        ])->assertOk();

        $cashier->refresh();
        $this->assertTrue($cashier->hasRole('supervisor'));
        $this->assertFalse($cashier->hasRole('cashier'));
        $this->assertSame(4, (int) $cashier->role_level);

        // Not above their own rank.
        $this->actingAs($manager)->putJson(route('domains.users.update', ['domain' => $this->domain->name_slug, 'user' => $cashier->id]), [
            'name' => $cashier->name, 'email' => $cashier->email, 'role_id' => $this->roles['admin']->id,
        ])->assertStatus(422)->assertJsonValidationErrors('role_id');
        $this->assertTrue($cashier->fresh()->hasRole('supervisor'));
    }

    private function location(string $name, string $type = 'store'): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => $type, 'is_active' => true, 'is_default' => false,
        ]);
    }

    public function test_staff_are_given_where_they_work_when_there_is_more_than_one_location(): void
    {
        $makati = $this->location('Makati');
        $warehouse = $this->location('Warehouse', 'warehouse');
        $admin = $this->member('admin');

        // Required for a manager-or-below role.
        $this->create($admin, 'cashier')->assertStatus(422)->assertJsonValidationErrors('location_id');

        // A cashier works at a store, not the warehouse.
        $this->actingAs($admin)->postJson(route('domains.users.store', ['domain' => $this->domain->name_slug]), [
            'name' => 'New cashier', 'email' => Str::random(8).'@example.test',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role_id' => $this->roles['cashier']->id, 'location_id' => $warehouse->id,
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');

        $this->actingAs($admin)->postJson(route('domains.users.store', ['domain' => $this->domain->name_slug]), [
            'name' => 'New cashier', 'email' => Str::random(8).'@example.test',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role_id' => $this->roles['cashier']->id, 'location_id' => $makati->id,
        ])->assertCreated();
        $cashier = User::query()->where('name', 'New cashier')->sole();
        $this->assertSame($makati->id, (int) $cashier->location_id);

        // A manager may work at the warehouse; moving a user is saved.
        $this->actingAs($admin)->putJson(route('domains.users.update', ['domain' => $this->domain->name_slug, 'user' => $cashier->id]), [
            'name' => $cashier->name, 'email' => $cashier->email, 'role_id' => $this->roles['manager']->id, 'location_id' => $warehouse->id,
        ])->assertOk();
        $this->assertSame($warehouse->id, (int) $cashier->fresh()->location_id);

        // An admin isn't tied to one location.
        $this->create($admin, 'admin')->assertCreated();

        // Another organization's store isn't on offer.
        $other = InventoryLocation::query()->create([
            'domain' => 'someone-else', 'name' => 'Theirs', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => false,
        ]);
        $this->actingAs($admin)->putJson(route('domains.users.update', ['domain' => $this->domain->name_slug, 'user' => $cashier->id]), [
            'name' => $cashier->name, 'email' => $cashier->email, 'role_id' => $this->roles['manager']->id, 'location_id' => $other->id,
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_with_one_location_staff_are_placed_there(): void
    {
        $only = $this->location('Only Store');

        $this->create($this->member('admin'), 'cashier')->assertCreated();

        $this->assertSame($only->id, (int) User::query()->where('name', 'New cashier')->sole()->location_id);
    }

    public function test_a_super_admin_role_never_makes_a_normal_account_a_super_user(): void
    {
        $user = $this->member('super admin');
        $user->syncRoleLevel();

        $this->assertSame(2, (int) $user->fresh()->role_level);
        $this->assertFalse($user->fresh()->isSuperUser());
    }
}
