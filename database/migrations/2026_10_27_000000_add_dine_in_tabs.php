<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dine-in: tables, open tabs and kitchen tickets.
 *
 * A tab is a pending sale that belongs to the store instead of one cashier's cart: it is opened on
 * a table (or under a name), stays open while the guests order, and is paid like any sale. Each
 * line remembers how much of it went to the kitchen, so a ticket only carries what is new.
 *
 * Permissions: php artisan db:seed --class=DineInPermissionSeeder --force
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->boolean('dine_in')->default(false)->after('is_default');
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('area', 50)->nullable();
            $table->unsignedSmallInteger('seats')->default(4);
            // Where it sits on the store's floor plan, in grid cells.
            $table->unsignedSmallInteger('pos_x')->default(0);
            $table->unsignedSmallInteger('pos_y')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'name']);
            $table->index(['domain', 'location_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('is_tab')->default(false)->after('payment_status');
            $table->foreignId('dining_table_id')->nullable()->after('is_tab')->constrained('dining_tables')->nullOnDelete();
            $table->string('tab_name', 60)->nullable()->after('dining_table_id');
            $table->unsignedSmallInteger('guests')->nullable()->after('tab_name');
            $table->foreignId('opened_by')->nullable()->after('guests')->constrained('users')->nullOnDelete();

            $table->index(['location_id', 'is_tab', 'payment_status']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            // How much of the line is already on a kitchen ticket.
            $table->decimal('sent_quantity', 12, 3)->default(0)->after('quantity');
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('label', 100);
            $table->boolean('is_addition')->default(false);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sale_id', 'number']);
        });

        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_ticket_id')->constrained('kitchen_tickets')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->string('name');
            $table->json('modifiers')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_items');
        Schema::dropIfExists('kitchen_tickets');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('sent_quantity');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['location_id', 'is_tab', 'payment_status']);
            $table->dropConstrainedForeignId('opened_by');
            $table->dropConstrainedForeignId('dining_table_id');
            $table->dropColumn(['is_tab', 'tab_name', 'guests']);
        });

        Schema::dropIfExists('dining_tables');

        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->dropColumn('dine_in');
        });
    }
};
