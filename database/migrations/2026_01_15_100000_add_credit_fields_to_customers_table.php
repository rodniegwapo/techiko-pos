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
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 12, 2)->default(0)->after('lifetime_spent');
            $table->decimal('credit_balance', 12, 2)->default(0)->after('credit_limit');
            $table->boolean('credit_enabled')->default(false)->after('credit_balance');
            $table->integer('credit_terms_days')->default(30)->after('credit_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'credit_limit',
                'credit_balance',
                'credit_enabled',
                'credit_terms_days',
            ]);
        });
    }
};
