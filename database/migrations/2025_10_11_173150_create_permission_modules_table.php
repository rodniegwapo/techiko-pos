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
        Schema::create('permission_modules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // 'users', 'products', 'inventory'
            $table->string('display_name'); // 'Users', 'Products', 'Inventory'
            $table->string('icon')->nullable(); // 'users', 'package', 'warehouse'
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_modules');
    }
};