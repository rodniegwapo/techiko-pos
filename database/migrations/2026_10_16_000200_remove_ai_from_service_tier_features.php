<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Every plan has the AI assistant now (limited by its AI credits), so no plan lists it as a feature. */
    public function up(): void
    {
        DB::table('service_tiers')->whereNotNull('features')->get(['id', 'features'])->each(function ($tier) {
            $features = json_decode($tier->features, true) ?: [];
            if (in_array('ai', $features, true)) {
                DB::table('service_tiers')->where('id', $tier->id)
                    ->update(['features' => json_encode(array_values(array_diff($features, ['ai'])))]);
            }
        });
    }

    public function down(): void
    {
        DB::table('service_tiers')->whereRaw('LOWER(slug) = ?', ['premium'])->get(['id', 'features'])->each(function ($tier) {
            $features = json_decode($tier->features ?? '[]', true) ?: [];
            DB::table('service_tiers')->where('id', $tier->id)
                ->update(['features' => json_encode(array_values(array_unique([...$features, 'ai'])))]);
        });
    }
};
