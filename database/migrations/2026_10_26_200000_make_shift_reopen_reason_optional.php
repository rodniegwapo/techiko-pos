<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A reopen is logged with who and when; the reason is optional. */
    public function up(): void
    {
        Schema::table('wallet_shift_reopen_logs', function (Blueprint $table) {
            $table->string('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_shift_reopen_logs', function (Blueprint $table) {
            $table->string('reason')->nullable(false)->default('')->change();
        });
    }
};
