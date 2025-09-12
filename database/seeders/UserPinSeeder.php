<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserPin;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserPinSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $manager = User::role('manager')->first();
        if ($manager) {
            UserPin::updateOrCreate(
                ['user_id' => $manager->id],
                [
                    'pin_code'  => Hash::make('1234'), // 🔑 default PIN (change in production)
                    'active'    => true,
                ]
            );
        }
    }
}
