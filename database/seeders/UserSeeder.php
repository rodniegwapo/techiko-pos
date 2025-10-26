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

        // Organization-specific users
        $users = [
            // Jollibee Corporation Users
            [
                'name' => 'Jollibee Admin',
                'email' => 'admin@jollibee-corp.com',
                'password' => 'jollibee123',
                'role' => 'admin',
                'domain' => 'jollibee-corp',
            ],
            [
                'name' => 'Jollibee Manager - Makati',
                'email' => 'manager@jollibee-corp.com',
                'password' => 'jollibee123',
                'role' => 'manager',
                'domain' => 'jollibee-corp',
                'location_code' => 'JB-MAIN',
            ],
            [
                'name' => 'Jollibee Manager - SM Mall',
                'email' => 'branch@jollibee-corp.com',
                'password' => 'jollibee123',
                'role' => 'manager',
                'domain' => 'jollibee-corp',
                'location_code' => 'JB-BRANCH',
            ],
            [
                'name' => 'Jollibee Cashier - Makati',
                'email' => 'cashier1@jollibee-corp.com',
                'password' => 'jollibee123',
                'role' => 'cashier',
                'domain' => 'jollibee-corp',
                'location_code' => 'JB-MAIN',
            ],
            [
                'name' => 'Jollibee Cashier - SM Mall',
                'email' => 'cashier2@jollibee-corp.com',
                'password' => 'jollibee123',
                'role' => 'cashier',
                'domain' => 'jollibee-corp',
                'location_code' => 'JB-BRANCH',
            ],

            // McDonald's Corporation Users
            [
                'name' => 'McDonald\'s Admin',
                'email' => 'admin@mcdonalds-corp.com',
                'password' => 'mcdonalds123',
                'role' => 'admin',
                'domain' => 'mcdonalds-corp',
            ],
            [
                'name' => 'McDonald\'s Manager - Ortigas',
                'email' => 'manager@mcdonalds-corp.com',
                'password' => 'mcdonalds123',
                'role' => 'manager',
                'domain' => 'mcdonalds-corp',
                'location_code' => 'MC-MAIN',
            ],
            [
                'name' => 'McDonald\'s Manager - BGC',
                'email' => 'branch@mcdonalds-corp.com',
                'password' => 'mcdonalds123',
                'role' => 'manager',
                'domain' => 'mcdonalds-corp',
                'location_code' => 'MC-BRANCH',
            ],
            [
                'name' => 'McDonald\'s Cashier - Ortigas',
                'email' => 'cashier1@mcdonalds-corp.com',
                'password' => 'mcdonalds123',
                'role' => 'cashier',
                'domain' => 'mcdonalds-corp',
                'location_code' => 'MC-MAIN',
            ],
            [
                'name' => 'McDonald\'s Cashier - BGC',
                'email' => 'cashier2@mcdonalds-corp.com',
                'password' => 'mcdonalds123',
                'role' => 'cashier',
                'domain' => 'mcdonalds-corp',
                'location_code' => 'MC-BRANCH',
            ],
        ];

        foreach ($users as $data) {
            // Determine role level based on role
            $roleLevel = match($data['role']) {
                'admin' => 2,
                'manager' => 3,
                'supervisor' => 4,
                'cashier' => 5,
                default => 3
            };

            // Get location ID if location_code is specified
            $locationId = null;
            if (isset($data['location_code'])) {
                $location = \App\Models\InventoryLocation::where('code', $data['location_code'])->first();
                $locationId = $location ? $location->id : null;
            }

            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make($data['password']),
                    'is_super_user' => false,
                    'domain' => $data['domain'],
                    'role_level' => $roleLevel,
                    'location_id' => $locationId,
                    'can_switch_locations' => $roleLevel <= 2, // Level 1-2 can switch locations
                ]
            );

            // Assign role using string value
            $user->assignRole($data['role']);
        }
    }
}

