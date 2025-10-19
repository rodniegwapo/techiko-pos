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
        // Add domain_id to customers table
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Add domain_id to inventory_locations table
        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Add domain_id to products table
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Add domain_id to sales table
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Add domain_id to stock_adjustments table
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Add domain_id to loyalty_tiers table
        Schema::table('loyalty_tiers', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Add domain_id to inventory_transfer_recommendations table
        Schema::table('inventory_transfer_recommendations', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->constrained()->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });

        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });

        Schema::table('loyalty_tiers', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });

        Schema::table('inventory_transfer_recommendations', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });
    }
};