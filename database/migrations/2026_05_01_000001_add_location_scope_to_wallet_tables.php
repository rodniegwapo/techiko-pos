<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_card_types', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('domain')
                ->constrained('inventory_locations')
                ->restrictOnDelete();
        });

        Schema::table('wallet_cash_movements', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('domain')
                ->constrained('inventory_locations')
                ->restrictOnDelete();
        });

        $this->backfillPaymentCardTypeLocations();
        $this->backfillWalletMovementLocations();

        Schema::table('payment_card_types', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->index(['domain', 'location_id', 'is_active'], 'pct_domain_location_active_idx');
            $table->index(['domain', 'location_id', 'sort_order', 'name'], 'pct_domain_location_sort_name_idx');
        });

        Schema::table('wallet_cash_movements', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->dropIndex('wcm_domain_card_date_idx');
            $table->dropIndex('wcm_domain_date_idx');
            $table->index(['domain', 'location_id', 'payment_card_type_id', 'movement_date'], 'wcm_domain_loc_card_date_idx');
            $table->index(['domain', 'location_id', 'movement_date'], 'wcm_domain_loc_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_cash_movements', function (Blueprint $table) {
            $table->dropIndex('wcm_domain_loc_card_date_idx');
            $table->dropIndex('wcm_domain_loc_date_idx');
            $table->index(['domain', 'payment_card_type_id', 'movement_date'], 'wcm_domain_card_date_idx');
            $table->index(['domain', 'movement_date'], 'wcm_domain_date_idx');
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('payment_card_types', function (Blueprint $table) {
            $table->dropIndex('pct_domain_location_active_idx');
            $table->dropIndex('pct_domain_location_sort_name_idx');
            $table->dropConstrainedForeignId('location_id');
        });
    }

    private function backfillPaymentCardTypeLocations(): void
    {
        $domainRows = DB::table('payment_card_types')
            ->select('domain')
            ->distinct()
            ->get();

        foreach ($domainRows as $row) {
            $defaultLocationId = $this->defaultLocationIdForDomain((string) $row->domain);
            if (! $defaultLocationId) {
                continue;
            }

            DB::table('payment_card_types')
                ->where('domain', $row->domain)
                ->whereNull('location_id')
                ->update(['location_id' => $defaultLocationId]);
        }
    }

    private function backfillWalletMovementLocations(): void
    {
        $rows = DB::table('wallet_cash_movements as w')
            ->leftJoin('payment_card_types as p', 'p.id', '=', 'w.payment_card_type_id')
            ->leftJoin('users as u', 'u.id', '=', 'w.user_id')
            ->whereNull('w.location_id')
            ->select('w.id', 'w.domain', 'p.location_id as card_location_id', 'u.location_id as user_location_id')
            ->orderBy('w.id')
            ->get();

        foreach ($rows as $row) {
            $locationId = $row->card_location_id ?: $row->user_location_id ?: $this->defaultLocationIdForDomain((string) $row->domain);
            if (! $locationId) {
                continue;
            }

            DB::table('wallet_cash_movements')
                ->where('id', $row->id)
                ->update(['location_id' => $locationId]);
        }
    }

    private function defaultLocationIdForDomain(string $domain): ?int
    {
        $default = DB::table('inventory_locations')
            ->where('domain', $domain)
            ->where('is_default', true)
            ->value('id');

        if ($default) {
            return (int) $default;
        }

        $firstActive = DB::table('inventory_locations')
            ->where('domain', $domain)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        return $firstActive ? (int) $firstActive : null;
    }
};
