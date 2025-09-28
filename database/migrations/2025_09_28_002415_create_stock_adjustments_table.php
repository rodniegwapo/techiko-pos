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
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number')->unique(); // ADJ-2025-001
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            
            // Adjustment details
            $table->enum('type', ['increase', 'decrease', 'recount']);
            $table->enum('reason', [
                'physical_count',
                'damaged_goods',
                'expired_goods',
                'theft_loss',
                'supplier_error',
                'system_error',
                'promotion',
                'sample',
                'other'
            ]);
            
            $table->text('description')->nullable();
            $table->decimal('total_value_change', 15, 4)->default(0);
            
            // Status tracking
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $table->timestamp('approved_at')->nullable();
            
            // User tracking
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            
            // Indexes
            $table->index('adjustment_number');
            $table->index('status');
            $table->index('created_at');
            $table->index(['location_id', 'status']);
            $table->index(['type', 'reason']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};