<?php

namespace Database\Seeders;

use App\Models\Domain;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DomainSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Jollibee Corporation — seeded as subscribed for local / PayMongo UI demos (no API keys required).
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
            'subscription_active' => true,
            'paymongo_customer_id' => 'cus_seed_jollibee_local',
            'paymongo_subscription_id' => 'sub_seed_jollibee_local',
            'subscription_status' => 'active',
            'subscription_current_period_end' => Carbon::now()->addMonth(),
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