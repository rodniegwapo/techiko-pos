<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role as SpatieRole;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure roles exist in DB
        foreach (Role::cases() as $enumRole) {
            SpatieRole::findOrCreate($enumRole->value, 'web');
        }

        // Create super user (Level 1 - no restrictions)
        $superUser = User::updateOrCreate(
            ['email' => 'super.admin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_super_user' => true,
                'role_level' => 1,
                'can_switch_locations' => true,
            ]
        );

        // Load domain slugs for assignment
        $domainSlugs = Domain::pluck('name_slug')->all();
        $pickDomain = function (int $i) use ($domainSlugs) {
            $count = max(count($domainSlugs), 1);
            return $domainSlugs[ $i % $count ] ?? null;
        };

        // Load inventory locations for assignment
        $locations = \App\Models\InventoryLocation::all();
        $pickLocation = function (int $i) use ($locations) {
            $count = max(count($locations), 1);
            return $locations[ $i % $count ]->id ?? null;
        };

        // Users mapped to their roles - Expanded for cascading hierarchy
        $users = [
            // Level 2 - Admin
            [
                'name' => 'John Admin',
                'email' => 'admin@example.com',
                'password' => 'admin',
                'role' => 'admin',
            ],
            [
                'name' => 'Sarah Admin',
                'email' => 'admin2@example.com',
                'password' => 'admin',
                'role' => 'admin',
            ],
            
            // Level 3 - Manager
            [
                'name' => 'Mike Manager',
                'email' => 'manager@example.com',
                'password' => 'manager',
                'role' => 'manager',
            ],
            [
                'name' => 'Lisa Manager',
                'email' => 'manager2@example.com',
                'password' => 'manager',
                'role' => 'manager',
            ],
            [
                'name' => 'David Manager',
                'email' => 'manager3@example.com',
                'password' => 'manager',
                'role' => 'manager',
            ],
            
            // Level 4 - Supervisor
            [
                'name' => 'Emma Supervisor',
                'email' => 'supervisor@example.com',
                'password' => 'supervisor',
                'role' => 'supervisor',
            ],
            [
                'name' => 'Tom Supervisor',
                'email' => 'supervisor2@example.com',
                'password' => 'supervisor',
                'role' => 'supervisor',
            ],
            [
                'name' => 'Anna Supervisor',
                'email' => 'supervisor3@example.com',
                'password' => 'supervisor',
                'role' => 'supervisor',
            ],
            [
                'name' => 'Chris Supervisor',
                'email' => 'supervisor4@example.com',
                'password' => 'supervisor',
                'role' => 'supervisor',
            ],
            
            // Level 5 - Cashier
            [
                'name' => 'Alex Cashier',
                'email' => 'cashier@example.com',
                'password' => 'cashier',
                'role' => 'cashier',
            ],
            [
                'name' => 'Maria Cashier',
                'email' => 'cashier2@example.com',
                'password' => 'cashier',
                'role' => 'cashier',
            ],
            [
                'name' => 'James Cashier',
                'email' => 'cashier3@example.com',
                'password' => 'cashier',
                'role' => 'cashier',
            ],
            [
                'name' => 'Sophie Cashier',
                'email' => 'cashier4@example.com',
                'password' => 'cashier',
                'role' => 'cashier',
            ],
            [
                'name' => 'Ryan Cashier',
                'email' => 'cashier5@example.com',
                'password' => 'cashier',
                'role' => 'cashier',
            ],
            [
                'name' => 'Nina Cashier',
                'email' => 'cashier6@example.com',
                'password' => 'cashier',
                'role' => 'cashier',
            ],
        ];

        $i = 0;
        foreach ($users as $data) {
            // Determine role level based on role
            $roleLevel = match($data['role']) {
                'admin' => 2,
                'manager' => 3,
                'supervisor' => 4,
                'cashier' => 5,
                default => 3
            };

            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make($data['password']),
                    'is_super_user' => false,
                    'domain' => $pickDomain($i),
                    'role_level' => $roleLevel,
                    'location_id' => $roleLevel >= 3 ? $pickLocation($i) : null, // Level 3+ get assigned locations
                    'can_switch_locations' => $roleLevel <= 2, // Level 1-2 can switch locations
                ]
            );

            // Assign role using string value
            $user->assignRole($data['role']);
            $i++;
        }
    }
}
