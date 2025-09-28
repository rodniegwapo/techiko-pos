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
        Schema::create('inventory_transfer_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('from_location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->foreignId('to_location_id')->constrained('inventory_locations')->cascadeOnDelete();
            
            // Recommendation details
            $table->integer('recommended_quantity');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('reason', [
                'low_stock',           // Destination is low on stock
                'out_of_stock',        // Destination is out of stock
                'excess_stock',        // Source has excess stock
                'demand_pattern',      // Based on sales velocity
                'seasonal_demand',     // Seasonal patterns
                'promotion_prep',      // Preparing for promotion
                'manual_request'       // Manually requested
            ]);
            
            // Analytics data
            $table->integer('current_stock_from')->default(0);
            $table->integer('current_stock_to')->default(0);
            $table->decimal('demand_velocity_to', 8, 2)->default(0); // Units per day
            $table->integer('days_of_stock_remaining')->default(0);
            $table->decimal('potential_lost_sales', 12, 4)->default(0);
            
            // Status and processing
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed', 'expired'])->default('pending');
            $table->timestamp('recommended_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['status', 'priority']);
            $table->index(['from_location_id', 'status']);
            $table->index(['to_location_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index('recommended_at');
            $table->index('expires_at');
            $table->index('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_recommendations');
    }
};