<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty tiers belong to an organization.
 *
 * They were seeded once for the whole installation, with no organization at all, while the Tier
 * Management page lists the tiers of the organization being worked in — so it showed none of them,
 * and a tier name taken by one organization was unavailable to every other. Each organization now
 * gets its own copy of the tiers that were shared, and a tier name only has to be unique within it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_tiers', function (Blueprint $table) {
            $table->dropUnique('loyalty_tiers_name_unique');
            $table->unique(['domain', 'name'], 'loyalty_tiers_domain_name_unique');
        });

        $shared = DB::table('loyalty_tiers')->whereNull('domain')->get();
        if ($shared->isEmpty()) {
            return;
        }

        $now = now();
        foreach (DB::table('domains')->pluck('name_slug') as $slug) {
            $own = DB::table('loyalty_tiers')->where('domain', $slug)->pluck('name')->all();

            $copies = $shared
                ->reject(fn ($tier) => in_array($tier->name, $own, true))
                ->map(function ($tier) use ($slug, $now) {
                    $copy = (array) $tier;
                    unset($copy['id']);

                    return array_merge($copy, [
                        'domain' => $slug,
                        'created_at' => $tier->created_at ?? $now,
                        'updated_at' => $now,
                    ]);
                })
                ->values()
                ->all();

            if ($copies) {
                DB::table('loyalty_tiers')->insert($copies);
            }
        }

        DB::table('loyalty_tiers')->whereNull('domain')->delete();
    }

    public function down(): void
    {
        // One row per name can survive a unique index on the name alone: keep the oldest of each.
        $keep = DB::table('loyalty_tiers')
            ->selectRaw('MIN(id) as id')
            ->groupBy('name')
            ->pluck('id');

        DB::table('loyalty_tiers')->whereNotIn('id', $keep)->delete();
        DB::table('loyalty_tiers')->update(['domain' => null]);

        Schema::table('loyalty_tiers', function (Blueprint $table) {
            $table->dropUnique('loyalty_tiers_domain_name_unique');
            $table->unique('name', 'loyalty_tiers_name_unique');
        });
    }
};
