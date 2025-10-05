<?php

namespace Database\Seeders;

use App\Enums\Role;
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
            SpatieRole::firstOrCreate(['name' => $enumRole->value]);
        }

        // Create super user (no role needed)
        $superUser = User::updateOrCreate(
            ['email' => 'super.admin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_super_user' => true,
            ]
        );

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

        foreach ($users as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make($data['password']),
                    'is_super_user' => false,
                ]
            );

            // Assign role using string value
            $user->assignRole($data['role']);
        }
    }
}
