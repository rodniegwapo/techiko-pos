<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill product_inventory.domain from inventory_locations.domain
        DB::statement('UPDATE product_inventory pi
            JOIN inventory_locations il ON il.id = pi.location_id
            SET pi.domain = il.domain
            WHERE pi.domain IS NULL');

        // Backfill inventory_movements.domain from inventory_locations.domain
        DB::statement('UPDATE inventory_movements im
            JOIN inventory_locations il ON il.id = im.location_id
            SET im.domain = il.domain
            WHERE im.domain IS NULL');
    }

    public function down(): void
    {
        // No-op: keep data
    }
};


