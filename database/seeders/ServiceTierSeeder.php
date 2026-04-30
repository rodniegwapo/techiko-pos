<?php

namespace Database\Seeders;

use App\Models\ServiceTier;
use Illuminate\Database\Seeder;

class ServiceTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            ['slug' => 'basic', 'name' => 'Basic servicing', 'amount' => '499.00', 'sort_order' => 10],
            ['slug' => 'standard', 'name' => 'Standard servicing', 'amount' => '999.00', 'sort_order' => 20],
            ['slug' => 'premium', 'name' => 'Premium servicing', 'amount' => '1999.00', 'sort_order' => 30],
        ];

        foreach ($tiers as $tier) {
            ServiceTier::updateOrCreate(
                ['slug' => $tier['slug']],
                [
                    'name' => $tier['name'],
                    'amount' => $tier['amount'],
                    'sort_order' => $tier['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
