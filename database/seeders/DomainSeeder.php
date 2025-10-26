<?php

namespace Database\Seeders;

use App\Models\Domain;
use Illuminate\Database\Seeder;

class DomainSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Jollibee Corporation
        Domain::create([
            'name' => 'Jollibee Corporation',
            'name_slug' => 'jollibee-corp',
            'timezone' => 'Asia/Manila',
            'country_code' => 'PH',
            'currency_code' => 'PHP',
            'date_format' => 'Y-m-d',
            'time_format' => '12h',
            'language_code' => 'en',
            'is_active' => true,
        ]);

        // McDonald's Corporation
        Domain::create([
            'name' => 'McDonald\'s Corporation',
            'name_slug' => 'mcdonalds-corp',
            'timezone' => 'Asia/Manila',
            'country_code' => 'PH',
            'currency_code' => 'PHP',
            'date_format' => 'Y-m-d',
            'time_format' => '12h',
            'language_code' => 'en',
            'is_active' => true,
        ]);
    }
}