<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The customer-facing display shows one store's open order. It used to show the newest pending
 * sale of any business to anyone: now its link carries the store's secret token, and only that
 * store's orders reach it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->string('display_token', 48)->nullable()->unique()->after('code');
        });

        DB::table('inventory_locations')->whereNull('display_token')->orderBy('id')->each(function ($location) {
            DB::table('inventory_locations')->where('id', $location->id)->update(['display_token' => Str::random(40)]);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->dropUnique(['display_token']);
            $table->dropColumn('display_token');
        });
    }
};
