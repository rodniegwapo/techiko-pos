<?php

namespace Database\Seeders;

use App\Models\ServiceTier;
use Illuminate\Database\Seeder;

class ServiceTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            ['slug' => 'basic', 'name' => 'Basic servicing', 'amount' => '499.00', 'sort_order' => 10, 'max_products' => null, 'max_users' => 5, 'ai_monthly_credits' => 500,
                'features' => ['sales_history_unlimited']],
            ['slug' => 'standard', 'name' => 'Standard servicing', 'amount' => '999.00', 'sort_order' => 20, 'max_products' => null, 'max_users' => null, 'ai_monthly_credits' => 1000,
                'features' => ['sales_history_unlimited', 'multi_location', 'advanced_inventory', 'expenses']],
            ['slug' => 'premium', 'name' => 'Premium servicing', 'amount' => '1999.00', 'sort_order' => 30, 'max_products' => null, 'max_users' => null, 'ai_monthly_credits' => 2000,
                'features' => ['sales_history_unlimited', 'multi_location', 'advanced_inventory', 'expenses', 'finance', 'ai']],
        ];

        foreach ($tiers as $tier) {
            ServiceTier::updateOrCreate(
                ['slug' => $tier['slug']],
                [
                    'name' => $tier['name'],
                    'amount' => $tier['amount'],
                    'sort_order' => $tier['sort_order'],
                    'max_products' => $tier['max_products'],
                    'max_users' => $tier['max_users'],
                    'ai_monthly_credits' => $tier['ai_monthly_credits'],
                    'features' => $tier['features'],
                    'is_active' => true,
                ]
            );
        }
    }
}
