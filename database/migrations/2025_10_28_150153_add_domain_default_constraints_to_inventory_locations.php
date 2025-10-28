<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table) {
            // Add index for domain and default combination
            // This will help with performance and we'll enforce uniqueness in application logic
            $table->index(['domain', 'is_default'], 'idx_domain_default');
        });
        
        // Handle existing data - ensure only one default per domain
        $this->fixExistingDefaults();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->dropIndex('idx_domain_default');
        });
    }
    
    /**
     * Fix existing default locations to ensure only one per domain
     */
    private function fixExistingDefaults(): void
    {
        // Get all domains
        $domains = \App\Models\Domain::all();
        
        foreach ($domains as $domain) {
            // Get all default locations for this domain
            $defaultLocations = \App\Models\InventoryLocation::where('domain', $domain->name_slug)
                ->where('is_default', true)
                ->get();
            
            if ($defaultLocations->count() > 1) {
                // Keep only the first one as default, unset the rest
                $defaultLocations->skip(1)->each(function ($location) {
                    $location->update(['is_default' => false]);
                });
            }
        }
        
        // Handle locations without domain (legacy data)
        $locationsWithoutDomain = \App\Models\InventoryLocation::whereNull('domain')
            ->where('is_default', true)
            ->get();
            
        if ($locationsWithoutDomain->count() > 1) {
            // Keep only the first one as default for legacy data
            $locationsWithoutDomain->skip(1)->each(function ($location) {
                $location->update(['is_default' => false]);
            });
        }
    }
};