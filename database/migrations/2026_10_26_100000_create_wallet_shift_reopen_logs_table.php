<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row each time a closed shift is reopened: who reopened it, why, and who had ended it
     * (the reconciliation row forgets that once it is reopened). Permissions are not touched here.
     */
    public function up(): void
    {
        Schema::create('wallet_shift_reopen_logs', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->foreignId('reconciliation_id')->nullable()->constrained('wallet_cash_reconciliations')->nullOnDelete();
            $table->date('business_date');
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('reason');
            $table->timestamps();

            $table->index(['domain', 'location_id', 'business_date'], 'wallet_shift_reopen_logs_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_shift_reopen_logs');
    }
};
