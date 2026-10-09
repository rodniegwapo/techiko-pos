<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business expenses (rent, salaries, utilities…) for the Profit & Loss report.
     * Cash-register expenses also post a cash-out to the wallet ledger, hence the new `expense` kind.
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['domain', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->string('description');
            $table->string('payee')->nullable();
            $table->enum('payment_method', ['cash_register', 'bank', 'ewallet', 'card', 'other']);
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('wallet_cash_movement_id')->nullable()->constrained('wallet_cash_movements')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['domain', 'expense_date']);
            $table->index(['domain', 'location_id', 'expense_date']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense') NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');

        if (DB::getDriverName() === 'mysql') {
            DB::table('wallet_cash_movements')->where('kind', 'expense')->update(['kind' => 'adjustment']);
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment') NOT NULL");
        }
    }
};
