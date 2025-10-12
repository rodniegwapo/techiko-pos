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
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('action')->nullable()->after('route_name'); // 'index', 'create', 'edit'
            $table->unsignedBigInteger('module_id')->nullable()->after('action');
            $table->foreign('module_id')->references('id')->on('permission_modules')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropForeign(['module_id']);
            $table->dropColumn(['action', 'module_id']);
        });
    }
};