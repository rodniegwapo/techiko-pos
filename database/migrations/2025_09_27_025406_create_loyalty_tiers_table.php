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
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // bronze, silver, gold, platinum
            $table->string('display_name'); // Bronze, Silver, Gold, Platinum
            $table->decimal('multiplier', 3, 2)->default(1.00); // 1.00, 1.25, 1.50, 2.00
            $table->decimal('spending_threshold', 10, 2)->default(0); // Minimum spending to reach this tier
            $table->string('color', 7)->default('#CD7F32'); // Hex color code
            $table->text('description')->nullable(); // Description of tier benefits
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0); // For ordering tiers
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
