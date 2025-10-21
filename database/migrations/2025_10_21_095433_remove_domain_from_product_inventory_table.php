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
        // Remove domain column from product_inventory as it's redundant
        // Domain can be derived from product relationship
        if (Schema::hasColumn('product_inventory', 'domain')) {
            Schema::table('product_inventory', function (Blueprint $table) {
                $table->dropColumn('domain');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add domain column back if needed
        if (!Schema::hasColumn('product_inventory', 'domain')) {
            Schema::table('product_inventory', function (Blueprint $table) {
                $table->string('domain')->nullable()->index()->after('id');
            });
        }
    }
};
