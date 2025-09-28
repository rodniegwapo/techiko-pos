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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            
            // Movement details
            $table->enum('movement_type', [
                'sale',           // Product sold
                'purchase',       // Product received from supplier
                'adjustment',     // Manual stock adjustment
                'transfer_in',    // Transfer from another location
                'transfer_out',   // Transfer to another location
                'return',         // Customer return
                'damage',         // Damaged goods
                'theft',          // Theft/loss
                'expired',        // Expired products
                'promotion'       // Promotional giveaway
            ]);
            
            $table->decimal('quantity_before', 12, 3); // Stock before movement
            $table->decimal('quantity_change', 12, 3); // +/- change amount
            $table->decimal('quantity_after', 12, 3);  // Stock after movement
            
            // Cost information
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('total_cost', 15, 4)->nullable();
            
            // Reference information
            $table->string('reference_type')->nullable(); // 'sale', 'purchase_order', 'adjustment'
            $table->unsignedBigInteger('reference_id')->nullable(); // ID of related record
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            
            // User and notes
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->text('reason')->nullable(); // For adjustments/losses
            
            $table->timestamps();
            
            // Indexes for performance and reporting
            $table->index('movement_type');
            $table->index('created_at');
            $table->index(['product_id', 'created_at']);
            $table->index(['location_id', 'created_at']);
            $table->index(['movement_type', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('batch_number');
            $table->index('expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};