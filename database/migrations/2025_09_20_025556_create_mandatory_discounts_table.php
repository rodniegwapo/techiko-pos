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
        Schema::create('mandatory_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Senior Citizen", "PWD"
            $table->enum('type', ['percentage', 'amount']);
            $table->decimal('value', 10, 2); // e.g., 20.00 (% or fixed)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mandatory_discounts');
    }
};
