<?php

namespace Database\Seeders;

use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Accounts used only by the Playwright suite (tests/e2e).
 *
 * The regular demo users from UserSeeder cover admin/manager/cashier. This adds:
 *  - a super user with a known password
 *  - a jollibee cashier-level user whose permissions exclude the dashboard,
 *    to prove permission checks deny access
 *
 * Refuses to run outside local/testing so a known super-user password never
 * lands in a real database.
 */
class E2EUserSeeder extends Seeder
{
    public const PASSWORD = 'e2e-password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EUserSeeder may only run in local or testing environments.');
        }

        User::updateOrCreate(
            ['email' => 'e2e-super@techiko.test'],
            [
                'name' => 'E2E Super User',
                'password' => Hash::make(self::PASSWORD),
                'is_super_user' => true,
                'role_level' => 1,
                'can_switch_locations' => true,
                'email_verified_at' => now(),
            ]
        );

        $limited = User::updateOrCreate(
            ['email' => 'e2e-no-dashboard@techiko.test'],
            [
                'name' => 'E2E No Dashboard',
                'password' => Hash::make(self::PASSWORD),
                'is_super_user' => false,
                'domain' => 'jollibee-corp',
                'role_level' => 5,
                'location_id' => InventoryLocation::where('code', 'JB-MAIN')->value('id'),
                'can_switch_locations' => false,
                'email_verified_at' => now(),
            ]
        );

        // Same access as a cashier, minus the dashboard, granted directly so no extra role is created.
        $permissions = SpatieRole::findByName('cashier', 'web')
            ->permissions
            ->reject(fn ($permission) => str_starts_with((string) $permission->route_name, 'dashboard'));

        $limited->syncRoles([]);
        $limited->syncPermissions($permissions);
    }
}
