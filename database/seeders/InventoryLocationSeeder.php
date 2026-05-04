<?php

namespace Database\Seeders;

use App\Models\Domain;
use App\Models\InventoryLocation;
use Illuminate\Database\Seeder;

/**
 * Seeds inventory locations early so CategorySeeder and ProductSeeder
 * (which require location_id) can run. InventorySeeder reuses the same
 * firstOrCreate logic via createForDomains().
 */
class InventoryLocationSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating inventory locations...');
        self::createForDomains($this);
    }

    /**
     * @return array<int, InventoryLocation>
     */
    public static function createForDomains(?Seeder $seeder = null): array
    {
        $locations = [];

        $domains = Domain::all();
        if ($domains->isEmpty()) {
            if ($seeder?->command) {
                $seeder->command->error('No domains found. Please run DomainSeeder first.');
            }

            return [];
        }

        foreach ($domains as $domain) {
            if (! in_array($domain->name_slug, ['jollibee-corp', 'mcdonalds-corp'], true)) {
                continue;
            }

            if ($domain->name_slug === 'jollibee-corp') {
                $locations[] = InventoryLocation::firstOrCreate(
                    ['code' => 'JB-MAIN'],
                    [
                        'name' => 'Jollibee Main Store - Makati',
                        'code' => 'JB-MAIN',
                        'type' => 'store',
                        'address' => '123 Ayala Avenue, Makati City',
                        'contact_person' => 'Jollibee Store Manager',
                        'phone' => '+63-2-123-4567',
                        'email' => 'main@jollibee-corp.com',
                        'is_active' => true,
                        'is_default' => true,
                        'domain' => $domain->name_slug,
                    ]
                );

                $locations[] = InventoryLocation::firstOrCreate(
                    ['code' => 'JB-BRANCH'],
                    [
                        'name' => 'Jollibee Branch Store - SM Mall',
                        'code' => 'JB-BRANCH',
                        'type' => 'store',
                        'address' => '456 SM Mall of Asia, Pasay City',
                        'contact_person' => 'Jollibee Branch Manager',
                        'phone' => '+63-2-234-5678',
                        'email' => 'branch@jollibee-corp.com',
                        'is_active' => true,
                        'is_default' => false,
                        'domain' => $domain->name_slug,
                    ]
                );

                $locations[] = InventoryLocation::firstOrCreate(
                    ['code' => 'JB-WH'],
                    [
                        'name' => 'Jollibee Distribution Center',
                        'code' => 'JB-WH',
                        'type' => 'warehouse',
                        'address' => '789 Industrial Park, Laguna',
                        'contact_person' => 'Jollibee Warehouse Manager',
                        'phone' => '+63-2-345-6789',
                        'email' => 'warehouse@jollibee-corp.com',
                        'is_active' => true,
                        'is_default' => false,
                        'domain' => $domain->name_slug,
                    ]
                );
            } elseif ($domain->name_slug === 'mcdonalds-corp') {
                $locations[] = InventoryLocation::firstOrCreate(
                    ['code' => 'MC-MAIN'],
                    [
                        'name' => 'McDonald\'s Main Store - Ortigas',
                        'code' => 'MC-MAIN',
                        'type' => 'store',
                        'address' => '123 Ortigas Avenue, Pasig City',
                        'contact_person' => 'McDonald\'s Store Manager',
                        'phone' => '+63-2-456-7890',
                        'email' => 'main@mcdonalds-corp.com',
                        'is_active' => true,
                        'is_default' => true,
                        'domain' => $domain->name_slug,
                    ]
                );

                $locations[] = InventoryLocation::firstOrCreate(
                    ['code' => 'MC-BRANCH'],
                    [
                        'name' => 'McDonald\'s Branch Store - BGC',
                        'code' => 'MC-BRANCH',
                        'type' => 'store',
                        'address' => '456 Bonifacio Global City, Taguig',
                        'contact_person' => 'McDonald\'s Branch Manager',
                        'phone' => '+63-2-567-8901',
                        'email' => 'branch@mcdonalds-corp.com',
                        'is_active' => true,
                        'is_default' => false,
                        'domain' => $domain->name_slug,
                    ]
                );

                $locations[] = InventoryLocation::firstOrCreate(
                    ['code' => 'MC-WH'],
                    [
                        'name' => 'McDonald\'s Distribution Center',
                        'code' => 'MC-WH',
                        'type' => 'warehouse',
                        'address' => '789 Logistics Hub, Cavite',
                        'contact_person' => 'McDonald\'s Warehouse Manager',
                        'phone' => '+63-2-678-9012',
                        'email' => 'warehouse@mcdonalds-corp.com',
                        'is_active' => true,
                        'is_default' => false,
                        'domain' => $domain->name_slug,
                    ]
                );
            }

            $locations[] = InventoryLocation::firstOrCreate(
                ['code' => 'CUST'],
                [
                    'name' => 'Customer Returns - '.$domain->name,
                    'code' => 'CUST',
                    'type' => 'customer',
                    'address' => 'Customer Service Area',
                    'contact_person' => 'Customer Service',
                    'phone' => '+63-2-456-7890',
                    'email' => 'service@'.$domain->name_slug.'.com',
                    'is_active' => true,
                    'is_default' => false,
                    'domain' => $domain->name_slug,
                ]
            );
        }

        return $locations;
    }
}
