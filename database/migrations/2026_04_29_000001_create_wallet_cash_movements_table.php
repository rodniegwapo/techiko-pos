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
        Schema::create('wallet_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('payment_card_type_id')
                ->nullable()
                ->constrained('payment_card_types')
                ->nullOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 12, 2);
            $table->enum('kind', [
                'cash_sale_topup',
                'owner_draw',
                'ewallet_transfer_in',
                'ewallet_transfer_out',
                'adjustment',
            ]);
            $table->text('notes')->nullable();
            $table->date('movement_date');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(
                ['domain', 'payment_card_type_id', 'movement_date'],
                'wcm_domain_card_date_idx',
            );
            $table->index(['domain', 'movement_date'], 'wcm_domain_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_cash_movements');
    }
};
