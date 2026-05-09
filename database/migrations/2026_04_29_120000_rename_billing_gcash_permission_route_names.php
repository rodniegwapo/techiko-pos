<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('route_name', 'billing.gcash.index')
            ->update([
                'route_name' => 'billing.servicing.index',
                'updated_at' => now(),
            ]);

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('route_name', 'billing.gcash.store')
            ->update([
                'route_name' => 'billing.servicing.manual_gcash',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('route_name', 'billing.servicing.index')
            ->update([
                'route_name' => 'billing.gcash.index',
                'updated_at' => now(),
            ]);

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('route_name', 'billing.servicing.manual_gcash')
            ->update([
                'route_name' => 'billing.gcash.store',
                'updated_at' => now(),
            ]);
    }
};
