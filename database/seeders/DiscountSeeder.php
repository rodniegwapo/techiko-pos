<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Domain;

class DiscountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $domains = Domain::pluck('name_slug')->all();

        $base = [
            // 🔹 Mandatory Discounts (Note: VAT is a tax, not a discount)
            // VAT should be handled separately as a tax, not as a discount
            [
                'name' => 'Senior Citizen Discount - 20%',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 20.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => null,
                'is_active' => true,
                'is_mandatory' => true,
            ],
            [
                'name' => 'PWD Discount - 20%',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 20.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => null,
                'is_active' => true,
                'is_mandatory' => true,
            ],

            // 🔹 Regular Discounts
            [
                'name' => '10% Off Order',
                'type' => 'percent',
                'scope' => 'product',
                'value' => 10.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addMonth(),
                'is_active' => true,
                'is_mandatory' => false,
            ],
            [
                'name' => '₱100 Off Product',
                'type' => 'amount',
                'scope' => 'product',
                'value' => 100.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addWeeks(2),
                'is_active' => true,
                'is_mandatory' => false,
            ],
            [
                'name' => '15% Off Category',
                'type' => 'percent',
                'scope' => 'category',
                'value' => 15.00,
                'min_order_amount' => 1000.00,
                'start_date' => Carbon::now()->subDay(),
                'end_date' => Carbon::now()->addDays(10),
                'is_active' => true,
                'is_mandatory' => false,
            ],

            // 🔹 Storewide Sale
            [
                'name' => '10% Off Storewide Weekend Sale',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 10.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addDays(3),
                'is_active' => true,
                'is_mandatory' => false,
            ],

            // 🔹 Holiday Promotions
            [
                'name' => 'Christmas Sale - 20% Off All Orders',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 20.00,
                'min_order_amount' => null,
                'start_date' => Carbon::parse('December 15'),
                'end_date' => Carbon::parse('December 31'),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Black Friday Mega Sale - 30% Off',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 30.00,
                'min_order_amount' => null,
                'start_date' => Carbon::parse('November 28'),
                'end_date' => Carbon::parse('November 30'),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 🔹 Special Event Discounts
            [
                'name' => 'Anniversary Sale - 25% Off',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 25.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addWeek(),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Grand Opening Discount - ₱200 Off',
                'type' => 'amount',
                'scope' => 'order',
                'value' => 200.00,
                'min_order_amount' => 1000.00,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addWeeks(2),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 🔹 Membership / Loyalty Discounts
            [
                'name' => 'Loyalty Card Holder Discount - 5%',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 5.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addYears(5),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Points Redemption ₱100 Off',
                'type' => 'amount',
                'scope' => 'order',
                'value' => 100.00,
                'min_order_amount' => 500.00,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addYears(3),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'VIP Customer Discount - 15%',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 15.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addYears(3),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            // 🔹 Purchase-Based Discounts
            [
                'name' => 'Bulk Purchase Discount - Spend ₱5,000 Get 10% Off',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 10.00,
                'min_order_amount' => 5000.00,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addMonths(6),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Minimum Spend ₱1,000 = ₱100 Off',
                'type' => 'amount',
                'scope' => 'order',
                'value' => 100.00,
                'min_order_amount' => 1000.00,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addMonths(6),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Buy More, Save More - Tiered Discount',
                'type' => 'tiered',
                'scope' => 'order',
                'value' => null, // handled in logic
                'min_order_amount' => 2000.00,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addMonths(6),
                'is_active' => true,
                'is_mandatory' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Insert per domain
        foreach ($domains as $slug) {
            $rows = array_map(function ($d) use ($slug) {
                return array_merge($d, [
                    'domain' => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }, $base);

            DB::table('discounts')->insert($rows);
        }
    }
}
