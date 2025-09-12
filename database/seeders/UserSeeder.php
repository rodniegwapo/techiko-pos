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

        // Users mapped to their roles
        $users = [
            [
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'password' => 'admin',
                'role' => Role::ADMIN,
            ],
            [
                'name' => 'Manager',
                'email' => 'manager@example.com',
                'password' => 'manager',
                'role' => Role::MANAGER,
            ],
            [
                'name' => 'Cashier',
                'email' => 'cashier@example.com',
                'password' => 'cashier',
                'role' => Role::CASHIER,
            ],
        ];

        foreach ($users as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make($data['password']),
                ]
            );

            // Assign role using enum value
            $user->assignRole($data['role']->value);
        }
    }
}
