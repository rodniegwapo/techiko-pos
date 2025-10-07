<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\UserPin;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            Roleseeder::class,
            UserSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            ProductSoldTypeSeeder::class,
            UserPinSeeder::class,
            DiscountSeeder::class,
            MandatoryDiscountSeeder::class,
            TierSeeder::class,
        ]);
    }
}
