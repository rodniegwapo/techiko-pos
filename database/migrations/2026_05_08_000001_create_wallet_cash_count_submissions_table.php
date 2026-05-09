<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_cash_count_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')
                ->constrained('inventory_locations')
                ->restrictOnDelete();
            $table->foreignId('reconciliation_id')
                ->nullable()
                ->constrained('wallet_cash_reconciliations')
                ->nullOnDelete();
            $table->date('business_date');
            $table->decimal('counted_cash', 12, 2);
            $table->decimal('expected_cash_snapshot', 12, 2)->nullable();
            $table->decimal('variance_snapshot', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('counted_by')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamp('counted_at');
            $table->timestamps();

            $table->index(
                ['domain', 'location_id', 'business_date', 'counted_at'],
                'wccs_domain_location_date_counted_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_cash_count_submissions');
    }
};
