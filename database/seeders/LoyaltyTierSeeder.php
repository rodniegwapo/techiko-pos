<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\LoyaltyTier;

class LoyaltyTierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tiers = [
            [
                'name' => 'bronze',
                'display_name' => 'Bronze',
                'multiplier' => 1.00,
                'spending_threshold' => 0,
                'color' => '#CD7F32',
                'description' => 'Welcome tier with standard benefits',
                'sort_order' => 1,
            ],
            [
                'name' => 'silver',
                'display_name' => 'Silver',
                'multiplier' => 1.25,
                'spending_threshold' => 20000,
                'color' => '#C0C0C0',
                'description' => '25% bonus points and priority support',
                'sort_order' => 2,
            ],
            [
                'name' => 'gold',
                'display_name' => 'Gold',
                'multiplier' => 1.50,
                'spending_threshold' => 50000,
                'color' => '#FFD700',
                'description' => '50% bonus points and exclusive offers',
                'sort_order' => 3,
            ],
            [
                'name' => 'platinum',
                'display_name' => 'Platinum',
                'multiplier' => 2.00,
                'spending_threshold' => 100000,
                'color' => '#E5E4E2',
                'description' => 'Double points and VIP treatment',
                'sort_order' => 4,
            ],
        ];

        foreach ($tiers as $tier) {
            LoyaltyTier::updateOrCreate(
                ['name' => $tier['name']],
                $tier
            );
        }
    }
}
