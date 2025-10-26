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
            // Core system seeders
            DomainSeeder::class,
            PermissionModuleSeeder::class,
            RolePermissionSeeder::class,
            Roleseeder::class,
            
            // Product and category seeders
            CategorySeeder::class,
            ProductSeeder::class,
            ProductSoldTypeSeeder::class,
            
            // Discount and loyalty seeders
            DiscountSeeder::class,
            MandatoryDiscountSeeder::class,
            TierSeeder::class,
            LoyaltyProgramSeeder::class,
            
            // Enhanced inventory seeders (must be before users)
            InventorySeeder::class,
            InventoryMovementSeeder::class,
            StockAdjustmentSeeder::class,
            InventoryTransferRecommendationSeeder::class,
            
            // Users (after locations are created)
            UserSeeder::class,
            
            // User authentication
            UserPinSeeder::class,
        ]);
    }
}
