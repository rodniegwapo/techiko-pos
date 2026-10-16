<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A PayMongo checkout can pay for several months, and a super admin confirms a PayMongo payment
     * arrived (it unlocks the organization right away; the confirmation is only the super admin's check).
     */
    public function up(): void
    {
        Schema::table('paymongo_checkouts', function (Blueprint $table) {
            $table->unsignedTinyInteger('months')->default(1)->after('amount_centavos');
        });

        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('paymongo_checkout_id');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
        });

        // Bank transfers and approved GCash requests were checked by a super admin when recorded.
        DB::table('subscription_payments')->where('method', '!=', 'paymongo')->update(['confirmed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
        });

        Schema::table('paymongo_checkouts', function (Blueprint $table) {
            $table->dropColumn('months');
        });
    }
};
