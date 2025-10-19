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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_slug')->unique();
            $table->string('timezone')->default('Asia/Manila');
            $table->string('country_code', 2)->default('PH');
            $table->string('currency_code', 3)->default('PHP');
            $table->string('date_format', 20)->default('Y-m-d');
            $table->string('time_format', 10)->default('12h');
            $table->string('language_code', 5)->default('en');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};