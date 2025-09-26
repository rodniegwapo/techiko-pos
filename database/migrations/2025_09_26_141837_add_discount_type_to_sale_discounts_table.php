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
        Schema::table('sale_discounts', function (Blueprint $table) {
            $table->enum('discount_type', ['regular', 'mandatory'])->default('regular')->after('discount_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_discounts', function (Blueprint $table) {
            $table->dropColumn('discount_type');
        });
    }
};
