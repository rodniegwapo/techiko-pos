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
        Schema::table('users', function (Blueprint $table) {
            $table->integer('role_level')->default(3)->after('domain');
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->after('role_level');
            $table->boolean('can_switch_locations')->default(false)->after('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn(['role_level', 'location_id', 'can_switch_locations']);
        });
    }
};
