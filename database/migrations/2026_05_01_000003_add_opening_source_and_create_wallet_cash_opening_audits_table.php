<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_cash_reconciliations', function (Blueprint $table) {
            $table->string('opening_source', 32)
                ->default('manual')
                ->after('opening_cash');
            $table->date('opening_source_date')
                ->nullable()
                ->after('opening_source');
        });

        Schema::create('wallet_cash_opening_audits', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')
                ->constrained('inventory_locations')
                ->restrictOnDelete();
            $table->date('business_date');
            $table->foreignId('reconciliation_id')
                ->nullable()
                ->constrained('wallet_cash_reconciliations')
                ->nullOnDelete();
            $table->decimal('old_opening_cash', 12, 2)->nullable();
            $table->decimal('new_opening_cash', 12, 2);
            $table->decimal('delta_amount', 12, 2);
            $table->foreignId('changed_by')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamp('changed_at');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(
                ['domain', 'location_id', 'business_date', 'changed_at'],
                'wcoa_domain_loc_date_changed_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_cash_opening_audits');

        Schema::table('wallet_cash_reconciliations', function (Blueprint $table) {
            $table->dropColumn(['opening_source', 'opening_source_date']);
        });
    }
};
