<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add domain column to inventory tables if missing
        if (!Schema::hasColumn('inventory_locations', 'domain')) {
            Schema::table('inventory_locations', function (Blueprint $table) {
                $table->string('domain')->nullable()->index()->after('id');
            });
        }

        if (!Schema::hasColumn('product_inventory', 'domain')) {
            Schema::table('product_inventory', function (Blueprint $table) {
                $table->string('domain')->nullable()->index()->after('id');
            });
        }

        if (!Schema::hasColumn('inventory_movements', 'domain')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->string('domain')->nullable()->index()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_locations', 'domain')) {
            Schema::table('inventory_locations', function (Blueprint $table) {
                $table->dropColumn('domain');
            });
        }

        if (Schema::hasColumn('product_inventory', 'domain')) {
            Schema::table('product_inventory', function (Blueprint $table) {
                $table->dropColumn('domain');
            });
        }

        if (Schema::hasColumn('inventory_movements', 'domain')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropColumn('domain');
            });
        }
    }
};


