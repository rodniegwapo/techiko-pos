<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit charges can be paid in part, and split into installments with their own due dates.
 * Payments also keep how they were paid (cash, card, e-wallet, bank).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            // How much of a credit (charge) has been paid so far; it is settled once this reaches amount.
            $table->decimal('paid_amount', 12, 2)->default(0)->after('amount');
            $table->string('payment_method')->nullable()->after('paid_at');
        });

        // Charges already marked paid were paid in full.
        DB::table('credit_transactions')
            ->where('transaction_type', 'credit')
            ->whereNotNull('paid_at')
            ->update(['paid_amount' => DB::raw('amount')]);

        Schema::create('credit_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_transaction_id')->constrained('credit_transactions')->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['credit_transaction_id', 'seq']);
            $table->index(['due_date', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_installments');

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'payment_method']);
        });
    }
};
