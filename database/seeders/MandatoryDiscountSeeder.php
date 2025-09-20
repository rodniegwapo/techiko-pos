<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MandatoryDiscountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('mandatory_discounts')->insert([
            [
                'name' => 'PWD',
                'type' => 'percentage',
                'value' => 10,
                'is_active' => true,
            ],
            [
                'name' => 'Senior',
                'type' => 'percentage',
                'value' => 20,
                'is_active' => true,
            ],
        ]);
    }
}
