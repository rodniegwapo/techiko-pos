<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DiscountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('discounts')->insert([
            // 🔹 Mandatory Discounts
            [
                'name' => 'Value Added Tax (VAT) 12%',
                'type' => 'percent',
                'scope' => 'order',
                'value' => 12.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => null, // no expiry
                'is_active' => true,
                'is_mandatory' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
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
                'created_at' => now(),
                'updated_at' => now(),
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
                'created_at' => now(),
                'updated_at' => now(),
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
                'created_at' => now(),
                'updated_at' => now(),
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
                'created_at' => now(),
                'updated_at' => now(),
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
                'created_at' => now(),
                'updated_at' => now(),
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
                'created_at' => now(),
                'updated_at' => now(),
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
        ]);
    }
}
