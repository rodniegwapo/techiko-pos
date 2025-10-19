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
        // Remove domain_id column from sales table since we now use domain string
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add domain_id column back
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->after('domain');
            $table->foreign('domain_id')->references('id')->on('domains')->onDelete('cascade');
        });
    }
};