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
        Schema::create('product_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            
            // Current stock levels
            $table->decimal('quantity_on_hand', 12, 3)->default(0);
            $table->decimal('quantity_reserved', 12, 3)->default(0); // For pending orders
            $table->decimal('quantity_available', 12, 3)->default(0); // on_hand - reserved
            
            // Cost tracking (FIFO/LIFO support)
            $table->decimal('average_cost', 12, 4)->default(0);
            $table->decimal('last_cost', 12, 4)->default(0);
            $table->decimal('total_value', 15, 4)->default(0); // quantity * average_cost
            
            // Tracking dates
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamp('last_restock_at')->nullable();
            $table->timestamp('last_sale_at')->nullable();
            
            $table->timestamps();
            
            // Unique constraint - one record per product per location
            $table->unique(['product_id', 'location_id']);
            
            // Indexes for performance
            $table->index('quantity_on_hand');
            $table->index('quantity_available');
            $table->index('last_movement_at');
            $table->index(['product_id', 'quantity_available']);
            $table->index(['location_id', 'quantity_on_hand']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_inventory');
    }
};