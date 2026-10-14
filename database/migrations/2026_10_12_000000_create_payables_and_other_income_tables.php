<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * The rest of the books next to Expenses: other income, suppliers, the bills they send and the
     * payments made on them. Expense categories get a type so the income statement can tell
     * running costs (operating) from one-off or non-operating ones (other). Money paid from or into
     * the cash register also posts to the wallet ledger, linked through wallet_cash_movement_id
     * like expenses are.
     */
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->enum('type', ['operating', 'other'])->default('operating')->after('name');
        });

        Schema::create('other_incomes', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->date('income_date');
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->enum('payment_method', ['cash_register', 'bank', 'ewallet', 'card', 'other']);
            $table->string('reference_no')->nullable();
            $table->foreignId('wallet_cash_movement_id')->nullable()->constrained('wallet_cash_movements')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['domain', 'income_date']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['domain', 'name']);
        });

        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('bill_number')->nullable();
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            // inventory: stock bought for resale (its cost reaches profit as cost of goods sold);
            // expense: a running cost billed by a supplier, counted under its category.
            $table->enum('bill_type', ['inventory', 'expense'])->default('inventory');
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['domain', 'bill_date']);
            $table->index(['domain', 'due_date']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 12, 2);
            $table->enum('payment_method', ['cash_register', 'bank', 'ewallet', 'card', 'other']);
            $table->string('reference_no')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('wallet_cash_movement_id')->nullable()->constrained('wallet_cash_movements')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['domain', 'payment_date']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_bills');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('other_incomes');

        if (DB::getDriverName() === 'mysql') {
            DB::table('wallet_cash_movements')->whereIn('kind', ['supplier_payment', 'other_income'])->update(['kind' => 'adjustment']);
            DB::statement("ALTER TABLE wallet_cash_movements MODIFY kind ENUM('cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense') NOT NULL");
        }

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
