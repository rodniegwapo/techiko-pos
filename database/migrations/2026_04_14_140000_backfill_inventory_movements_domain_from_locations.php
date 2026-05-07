<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill inventory_movements.domain from inventory_locations for rows
     * created after the original backfill or without domain on insert.
     */
    public function up(): void
    {
        DB::statement('UPDATE inventory_movements im
            INNER JOIN inventory_locations il ON il.id = im.location_id
            SET im.domain = il.domain
            WHERE im.domain IS NULL AND il.domain IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentional no-op: do not clear domain data
    }
};
