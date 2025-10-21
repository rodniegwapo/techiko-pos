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
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['product_id']);
            
            // Make product_id nullable to allow null values
            $table->unsignedBigInteger('product_id')->nullable()->change();
            
            // Add new foreign key constraint with nullOnDelete
            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            // Drop the new foreign key constraint
            $table->dropForeign(['product_id']);
            
            // Make product_id not nullable again
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
            
            // Restore the original foreign key constraint with restrictOnDelete
            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->restrictOnDelete();
        });
    }
};
