<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Templates (monthly rent, weekly wages…) that turn into real expenses when they fall due.
     * The unique (recurring_expense_id, expense_date) key keeps a run from ever being booked twice.
     */
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->string('payee')->nullable();
            $table->enum('payment_method', ['cash_register', 'bank', 'ewallet', 'card', 'other']);
            $table->enum('frequency', ['monthly', 'weekly']);
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1–31, clamped to the month's last day
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0 = Sunday … 6 = Saturday
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_run_date');
            $table->boolean('is_active')->default(true);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['domain', 'is_active', 'next_run_date']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')->nullable()->after('receipt_path')
                ->constrained('recurring_expenses')->nullOnDelete();
            $table->unique(['recurring_expense_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['recurring_expense_id', 'expense_date']);
            $table->dropConstrainedForeignId('recurring_expense_id');
        });
        Schema::dropIfExists('recurring_expenses');
    }
};
