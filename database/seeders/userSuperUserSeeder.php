<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class userSuperUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('SUPER_USER_EMAIL')],
            [
                'name' => 'Techiko',
                'password' => Hash::make(env('SUPER_USER_PASSWORD')),
                'is_super_user' => true,
                'role_level' => 1,
            ]
        );
    }
}
