<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sale can be paid in parts (cash + GCash, card + credit…). Such a sale has payment_method 'split'
 * and one row here per part; a sale paid one way keeps that way on the sale itself, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->enum('method', ['cash', 'card', 'e-wallet', 'bank', 'credit']);
            $table->foreignId('payment_card_type_id')->nullable()->constrained('payment_card_types')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            // What this part paid toward the sale; for cash, net of the change given back.
            $table->decimal('amount', 12, 2);
            // Cash only: what the customer handed over.
            $table->decimal('tendered', 12, 2)->nullable();
            $table->timestamps();

            $table->index(['method', 'payment_card_type_id']);
        });

        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'card', 'e-wallet', 'bank', 'credit', 'split') DEFAULT 'cash'");
    }

    public function down(): void
    {
        DB::statement("UPDATE sales SET payment_method = 'cash' WHERE payment_method = 'split'");
        DB::statement("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash', 'card', 'e-wallet', 'bank', 'credit') DEFAULT 'cash'");

        Schema::dropIfExists('sale_payments');
    }
};
