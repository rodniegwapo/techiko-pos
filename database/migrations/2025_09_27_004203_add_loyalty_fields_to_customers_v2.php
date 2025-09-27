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
            $table->date('date_of_birth')->nullable()->after('email');
            $table->enum('tier', ['bronze', 'silver', 'gold', 'platinum'])->default('bronze')->after('loyalty_points');
            $table->decimal('lifetime_spent', 12, 2)->default(0)->after('tier');
            $table->integer('total_purchases')->default(0)->after('lifetime_spent');
            $table->date('tier_achieved_date')->nullable()->after('total_purchases');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'tier', 'lifetime_spent', 'total_purchases', 'tier_achieved_date']);
        });
    }
};
