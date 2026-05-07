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
        Schema::create('payment_card_types', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['domain', 'is_active']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('payment_card_type_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('payment_card_types')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_card_type_id');
        });

        Schema::dropIfExists('payment_card_types');
    }
};
