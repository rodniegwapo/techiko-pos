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
        Domain::create([
            'name' => 'Rodnie Store',
            'name_slug' => 'rodnie-store',
            'timezone' => 'Asia/Manila',
            'country_code' => 'PH',
            'currency_code' => 'PHP',
            'date_format' => 'Y-m-d',
            'time_format' => '12h',
            'language_code' => 'en',
            'is_active' => true,
        ]);

        Domain::create([
            'name' => 'Tokyo Branch',
            'name_slug' => 'tokyo-branch',
            'timezone' => 'Asia/Tokyo',
            'country_code' => 'JP',
            'currency_code' => 'JPY',
            'date_format' => 'Y-m-d',
            'time_format' => '24h',
            'language_code' => 'ja',
            'is_active' => true,
        ]);

        Domain::create([
            'name' => 'New York Branch',
            'name_slug' => 'new-york-branch',
            'timezone' => 'America/New_York',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'date_format' => 'm/d/Y',
            'time_format' => '12h',
            'language_code' => 'en',
            'is_active' => true,
        ]);
    }
}