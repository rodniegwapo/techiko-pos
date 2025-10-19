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
        // Tables that already have domain_id and need to be converted
        $tables = ['categories', 'discounts', 'mandatory_discounts'];
        
        foreach ($tables as $table) {
            // Step 1: Add new domain column (only if it doesn't exist)
            if (!Schema::hasColumn($table, 'domain')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->string('domain')->nullable()->after('domain_id');
                });
            }
            
            // Step 2: Populate domain column from domain_id (only for records with valid domain_id)
            DB::statement("
                UPDATE {$table} t 
                JOIN domains d ON t.domain_id = d.id 
                SET t.domain = d.name_slug
                WHERE t.domain_id IS NOT NULL
            ");
            
            // Step 3: Set default domain for records without domain_id (if any)
            DB::statement("
                UPDATE {$table} 
                SET domain = 'default' 
                WHERE domain IS NULL
            ");
            
            // Step 4: Make domain column NOT NULL
            Schema::table($table, function (Blueprint $table) {
                $table->string('domain')->nullable(false)->change();
            });
            
            // Step 5: Drop foreign key constraint
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['domain_id']);
            });
            
            // Step 6: Drop domain_id column
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('domain_id');
            });
            
            // Step 7: Add index for performance
            Schema::table($table, function (Blueprint $table) {
                $table->index('domain');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['categories', 'discounts', 'mandatory_discounts'];
        
        foreach ($tables as $table) {
            // Step 1: Add domain_id column back
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('domain_id')->nullable()->after('domain');
            });
            
            // Step 2: Populate domain_id from domain
            DB::statement("
                UPDATE {$table} t 
                JOIN domains d ON t.domain = d.name_slug 
                SET t.domain_id = d.id
            ");
            
            // Step 3: Add foreign key constraint
            Schema::table($table, function (Blueprint $table) {
                $table->foreign('domain_id')->references('id')->on('domains')->onDelete('cascade');
            });
            
            // Step 4: Drop domain column
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('domain');
            });
        }
    }
};