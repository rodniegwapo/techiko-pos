<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Core system seeders
            DomainSeeder::class,
            PermissionModuleSeeder::class,
            RolePermissionSeeder::class,
            Roleseeder::class,

            ProductSoldTypeSeeder::class,
            InventoryLocationSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,

            // Discount and loyalty seeders
            DiscountSeeder::class,
            MandatoryDiscountSeeder::class,
            TierSeeder::class,
            LoyaltyProgramSeeder::class,

            // Enhanced inventory seeders (after products exist)
            InventorySeeder::class,

            // Users (after locations exist; before movement seeders that need user_id)
            UserSeeder::class,
            UserPinSeeder::class,

            InventoryMovementSeeder::class,
            StockAdjustmentSeeder::class,
            InventoryTransferRecommendationSeeder::class,
        ]);
    }
}
