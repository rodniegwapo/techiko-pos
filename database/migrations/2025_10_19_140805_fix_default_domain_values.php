<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get the first available domain
        $firstDomain = DB::table('domains')->where('is_active', true)->first();
        
        if (!$firstDomain) {
            // If no domains exist, create a default one
            $firstDomain = DB::table('domains')->insertGetId([
                'name' => 'Default Store',
                'name_slug' => 'default-store',
                'timezone' => 'Asia/Manila',
                'country_code' => 'PH',
                'currency_code' => 'PHP',
                'date_format' => 'Y-m-d',
                'time_format' => '12h',
                'language_code' => 'en',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $firstDomain = DB::table('domains')->where('id', $firstDomain)->first();
        }
        
        $domainSlug = $firstDomain->name_slug;
        
        // Update all tables that have 'default' domain values
        $tables = [
            'categories',
            'discounts', 
            'mandatory_discounts',
            'sales',
            'products',
            'customers',
            'users',
            'loyalty_tiers',
            'stock_adjustments',
            'inventory_locations',
            'inventory_transfer_recommendations',
            'roles'
        ];
        
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'domain')) {
                $count = DB::table($table)->where('domain', 'default')->count();
                
                if ($count > 0) {
                    echo "Updating {$count} records in {$table} table...\n";
                    
                    DB::table($table)
                        ->where('domain', 'default')
                        ->update(['domain' => $domainSlug]);
                        
                    echo "Updated {$table} table successfully.\n";
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Get the first available domain
        $firstDomain = DB::table('domains')->where('is_active', true)->first();
        
        if ($firstDomain) {
            $domainSlug = $firstDomain->name_slug;
            
            // Revert all tables back to 'default'
            $tables = [
                'categories',
                'discounts', 
                'mandatory_discounts',
                'sales',
                'products',
                'customers',
                'users',
                'loyalty_tiers',
                'stock_adjustments',
                'inventory_locations',
                'inventory_transfer_recommendations',
                'roles'
            ];
            
            foreach ($tables as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'domain')) {
                    DB::table($table)
                        ->where('domain', $domainSlug)
                        ->update(['domain' => 'default']);
                }
            }
        }
    }
};