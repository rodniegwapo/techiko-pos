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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sold_type');
            $table->integer('price');
            $table->integer('cost');
            $table->string('SKU');
            $table->string('barcode');
            $table->string('representation_type')->nullable();
            $table->longText('representation')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
