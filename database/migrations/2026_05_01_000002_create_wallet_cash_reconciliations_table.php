<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_cash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')
                ->constrained('inventory_locations')
                ->restrictOnDelete();
            $table->date('business_date');
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'location_id', 'business_date'], 'wcr_domain_location_date_unique');
            $table->index(['domain', 'location_id', 'business_date'], 'wcr_domain_location_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_cash_reconciliations');
    }
};
