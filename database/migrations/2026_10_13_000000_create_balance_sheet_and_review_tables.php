<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * The rest of the balance sheet and the monthly business review:
     * - money accounts (bank, e-wallet) with the balances the owner reads off them;
     * - loans and their repayments; equipment and other assets; owner investments;
     * - stored monthly reviews.
     * Money moved through the cash register posts to the wallet ledger, linked through
     * wallet_cash_movement_id like expenses, supplier payments and other income.
     */
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('name');
            $table->enum('type', ['bank', 'ewallet', 'other'])->default('bank');
            $table->string('account_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['domain', 'name']);
        });

        // The balance shown by the bank or e-wallet on a day; the latest one counts.
        Schema::create('financial_account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->cascadeOnDelete();
            $table->date('as_of_date');
            $table->decimal('balance', 14, 2);
            $table->string('note')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['financial_account_id', 'as_of_date']);
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('lender');
            $table->date('received_date');
            $table->decimal('principal', 14, 2);
            $table->decimal('interest_rate', 6, 3)->nullable(); // yearly %, for reference
            $table->date('due_date')->nullable();
            $table->enum('payment_method', ['cash_register', 'bank', 'ewallet', 'card', 'other']);
            $table->string('notes')->nullable();
            $table->foreignId('wallet_cash_movement_id')->nullable()->constrained('wallet_cash_movements')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->date('payment_date');
            $table->decimal('principal', 14, 2);
            // Interest paid is also booked as an expense (category of type "other"), linked here.
            $table->decimal('interest', 14, 2)->default(0);
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->enum('payment_method', ['cash_register', 'bank', 'ewallet', 'card', 'other']);
            $table->string('reference_no')->nullable();
            $table->foreignId('wallet_cash_movement_id')->nullable()->constrained('wallet_cash_movements')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['domain', 'payment_date']);
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('name');
            $table->enum('category', ['equipment', 'furniture', 'vehicle', 'building', 'other'])->default('equipment');
            $table->date('purchase_date');
            $table->decimal('cost', 14, 2);
            // Spread the cost over this many months (straight line); empty = not depreciated.
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->enum('payment_method', ['cash_register', 'bank', 'ewallet', 'card', 'other']);
            $table->date('disposed_date')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('wallet_cash_movement_id')->nullable()->constrained('wallet_cash_movements')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('owner_investments', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->date('investment_date');
            $table->decimal('amount', 14, 2);
            $table->enum('payment_method', ['cash_register', 'bank', 'ewallet', 'card', 'other']);
            $table->string('notes')->nullable();
            $table->foreignId('wallet_cash_movement_id')->nullable()->constrained('wallet_cash_movements')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('monthly_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->char('month', 7); // Y-m
            $table->json('figures');
            $table->json('went_well');
            $table->json('needs_attention');
            $table->json('actions');
            $table->text('ai_summary')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'month']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income','loan_received','loan_payment','asset_purchase','owner_investment') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_reviews');
        Schema::dropIfExists('owner_investments');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('financial_account_balances');
        Schema::dropIfExists('financial_accounts');

        if (DB::getDriverName() === 'mysql') {
            DB::table('wallet_cash_movements')
                ->whereIn('kind', ['loan_received', 'loan_payment', 'asset_purchase', 'owner_investment'])
                ->update(['kind' => 'adjustment']);
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income') NOT NULL");
        }
    }
};
