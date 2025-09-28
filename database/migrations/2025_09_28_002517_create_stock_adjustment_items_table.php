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
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            
            // Quantity details
            $table->decimal('system_quantity', 12, 3); // What system shows
            $table->decimal('actual_quantity', 12, 3);  // What was counted/found
            $table->decimal('adjustment_quantity', 12, 3); // Difference (actual - system)
            
            // Cost information
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('total_cost_change', 15, 4); // adjustment_quantity * unit_cost
            
            // Additional details
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['stock_adjustment_id', 'product_id']);
            $table->index('batch_number');
            $table->index('expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};