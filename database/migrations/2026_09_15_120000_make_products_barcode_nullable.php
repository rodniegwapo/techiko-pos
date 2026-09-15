<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products without a barcode used to store '' and the (domain, barcode) unique index then allowed
 * only one such product per organization. NULLs don't collide in a unique index, so a missing
 * barcode is stored as NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode')->nullable()->change();
        });

        DB::table('products')->where('barcode', '')->update(['barcode' => null]);
    }

    public function down(): void
    {
        // Rolling back only works while at most one product per organization lacks a barcode.
        DB::table('products')->whereNull('barcode')->update(['barcode' => '']);

        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode')->nullable(false)->change();
        });
    }
};
