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
            ServiceTierSeeder::class,

            InventoryLocationSeeder::class,

            // Product and category seeders
            CategorySeeder::class,
            ProductSeeder::class,
            ProductSoldTypeSeeder::class,

            // Discount and loyalty seeders
            DiscountSeeder::class,
            MandatoryDiscountSeeder::class,
            TierSeeder::class,
            LoyaltyProgramSeeder::class,

            // Product inventory rows (needs products)
            InventorySeeder::class,

            // Users before movement/adjustment seeders (they assign user_id from seeded users)
            UserSeeder::class,
            UserPinSeeder::class,

            InventoryMovementSeeder::class,
            StockAdjustmentSeeder::class,
            InventoryTransferRecommendationSeeder::class,
        ]);
    }
}
