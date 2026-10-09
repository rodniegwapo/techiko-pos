<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product modifiers (add-ons): groups of options picked when a product is rung up, e.g. Size
 * (Small / Medium +₱10 / Large +₱20) or Add-ons (Extra shot +₱20, No sugar). A group belongs to an
 * organization and is attached to the products it applies to. A sale line keeps the options picked,
 * named and priced as they were at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('name');
            // single: pick one (radio); multiple: pick any number up to max_select.
            $table->enum('selection', ['single', 'multiple'])->default('single');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('max_select')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->decimal('cost_delta', 12, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_modifier_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unique(['product_id', 'modifier_group_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            // The options picked, as a key, so two lines of the same product with different options stay apart.
            $table->string('modifier_key', 191)->nullable()->after('product_id');
            $table->string('notes', 200)->nullable()->after('modifier_key');
        });

        Schema::create('sale_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained('sale_items')->cascadeOnDelete();
            $table->foreignId('modifier_id')->nullable()->constrained('modifiers')->nullOnDelete();
            $table->string('group_name');
            $table->string('name');
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->decimal('cost_delta', 12, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_modifiers');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['modifier_key', 'notes']);
        });

        Schema::dropIfExists('product_modifier_group');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
    }
};
