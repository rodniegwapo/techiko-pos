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
            [
                'name' => '10% Off Order',
                'type' => 'percentage',
                'scope' => 'order',
                'value' => 10.00,
                'min_order_amount' => null,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addMonth(),
                'is_active' => true,
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
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '15% Off Category',
                'type' => 'percentage',
                'scope' => 'category',
                'value' => 15.00,
                'min_order_amount' => 1000.00,
                'start_date' => Carbon::now()->subDay(),
                'end_date' => Carbon::now()->addDays(10),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
