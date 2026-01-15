<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\LoyaltyTier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LoyaltyProgramSeeder extends Seeder
{
    public function run(): void
    {
        $domains = Domain::pluck('name_slug')->all();

        // Seed global loyalty tiers once (table enforces unique name)
        $tiers = [
            ['name' => 'bronze',   'display_name' => 'Bronze',   'multiplier' => 1.00, 'spending_threshold' => 0,     'sort_order' => 1],
            ['name' => 'silver',   'display_name' => 'Silver',   'multiplier' => 1.25, 'spending_threshold' => 20000, 'sort_order' => 2],
            ['name' => 'gold',     'display_name' => 'Gold',     'multiplier' => 1.50, 'spending_threshold' => 50000, 'sort_order' => 3],
            ['name' => 'platinum', 'display_name' => 'Platinum', 'multiplier' => 2.00, 'spending_threshold' => 100000, 'sort_order' => 4],
        ];

        foreach ($tiers as $t) {
            LoyaltyTier::updateOrCreate(
                ['name' => $t['name']],
                [
                    'display_name' => $t['display_name'],
                    'multiplier' => $t['multiplier'],
                    'spending_threshold' => $t['spending_threshold'],
                    'color' => match ($t['name']) {
                        'bronze' => '#CD7F32',
                        'silver' => '#C0C0C0',
                        'gold' => '#FFD700',
                        default => '#E5E4E2',
                    },
                    'description' => $t['display_name'].' loyalty tier',
                    'sort_order' => $t['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        // Seed sample customers per domain
        foreach ($domains as $slug) {

            // Seed some customers with loyalty activity for this domain (idempotent-ish by email)
            $sample = [
                ['name' => 'John Walker',   'email' => 'john.'.Str::slug($slug).'@example.com',   'tier' => 'bronze',   'points' => 1200,  'spent' => 15000,  'purchases' => 8,  'credit_limit' => 10000],
                ['name' => 'Alice Carter',  'email' => 'alice.'.Str::slug($slug).'@example.com',  'tier' => 'silver',   'points' => 4200,  'spent' => 32000,  'purchases' => 16, 'credit_limit' => 25000],
                ['name' => 'Robert Davis',  'email' => 'robert.'.Str::slug($slug).'@example.com', 'tier' => 'gold',     'points' => 9800,  'spent' => 62000,  'purchases' => 28, 'credit_limit' => 50000],
                ['name' => 'Sophia Miller', 'email' => 'sophia.'.Str::slug($slug).'@example.com', 'tier' => 'platinum', 'points' => 15200, 'spent' => 128000, 'purchases' => 55, 'credit_limit' => 100000],
            ];

            foreach ($sample as $c) {
                Customer::updateOrCreate(
                    ['email' => $c['email']],
                    [
                        'name' => $c['name'],
                        'phone' => '09'.rand(100000000, 999999999),
                        'domain' => $slug,
                        'tier' => $c['tier'],
                        'loyalty_points' => $c['points'],
                        'lifetime_spent' => $c['spent'],
                        'total_purchases' => $c['purchases'],
                        'credit_enabled' => true,
                        'credit_limit' => $c['credit_limit'],
                        'credit_balance' => 0,
                        'credit_terms_days' => 30,
                    ]
                );
            }
        }
    }
}
