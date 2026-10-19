<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product's selling price at one store, when it differs from the product's own price. Empty
 * means the store sells it at the product's price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_product', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('location_product', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
