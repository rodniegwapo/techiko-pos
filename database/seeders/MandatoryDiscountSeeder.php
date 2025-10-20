<?php

namespace Database\Seeders;

use App\Models\Domain;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MandatoryDiscountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $domains = Domain::pluck('name_slug')->all();

        foreach ($domains as $slug) {
            DB::table('mandatory_discounts')->insert([
                [
                    'name' => 'PWD',
                    'type' => 'percentage',
                    'value' => 10,
                    'is_active' => true,
                    'domain' => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Senior',
                    'type' => 'percentage',
                    'value' => 20,
                    'is_active' => true,
                    'domain' => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}
