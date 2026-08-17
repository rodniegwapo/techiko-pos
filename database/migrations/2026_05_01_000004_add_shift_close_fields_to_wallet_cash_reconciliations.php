<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_cash_reconciliations', function (Blueprint $table) {
            $table->boolean('is_closed')->default(false)->after('counted_cash');
            $table->timestamp('closed_at')->nullable()->after('is_closed');
            $table->foreignId('closed_by')
                ->nullable()
                ->after('closed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reopened_at')->nullable()->after('closed_by');
            $table->foreignId('reopened_by')
                ->nullable()
                ->after('reopened_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_cash_reconciliations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn('reopened_at');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_at', 'is_closed']);
        });
    }
};
