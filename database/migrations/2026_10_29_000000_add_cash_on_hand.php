<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash on hand: the business's cash the owner holds, out of every drawer. Collecting a shift, the
 * end-of-day cash-out and moving it back to a store only change where the money is; "Owner used
 * it" is the one line that takes it out of the business.
 * - cash_on_hand_movements: its lines (a collected shift once only: register_shift_id is unique);
 * - register_shifts.collected_*: what the owner collected from the shift, by whom and when;
 * - inventory_locations.allows_money_transfer: the store may send and receive store-to-store transfers;
 * - wallet_cash_movements.kind: to_cash_on_hand / from_cash_on_hand, the store's side of those moves.
 *
 * Permissions: php artisan db:seed --class=CashOnHandPermissionSeeder --force
 */
return new class extends Migration
{
    private const KINDS = "'cash_sale_topup','owner_draw','ewallet_transfer_in','ewallet_transfer_out','adjustment','expense','supplier_payment','other_income','loan_received','loan_payment','asset_purchase','owner_investment','sale_payment','sale_void','credit_payment','owner_transfer_in','transfer_out','transfer_in'";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE wallet_cash_movements MODIFY kind ENUM('.self::KINDS.",'to_cash_on_hand','from_cash_on_hand') NOT NULL");
        }

        Schema::create('cash_on_hand_movements', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();
            $table->string('direction', 3);
            $table->decimal('amount', 12, 2);
            $table->string('kind', 30);
            // The store the cash came from or went to, and the shift it was collected from.
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('register_shift_id')->nullable()->unique()->constrained('register_shifts')->nullOnDelete();
            // The store day an end-of-day cash-out came from.
            $table->date('source_date')->nullable();
            $table->foreignId('payment_card_type_id')->nullable()->constrained('payment_card_types')->nullOnDelete();
            // Pairs the line with the drawer or channel line on the store's side.
            $table->uuid('transfer_group')->nullable()->index();
            $table->date('movement_date');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['domain', 'movement_date']);
            $table->index(['domain', 'location_id', 'source_date']);
        });

        Schema::table('register_shifts', function (Blueprint $table) {
            $table->decimal('collected_amount', 12, 2)->nullable()->after('review_note');
            $table->foreignId('collected_by')->nullable()->after('collected_amount')->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->nullable()->after('collected_by');
        });

        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->boolean('allows_money_transfer')->default(false)->after('table_cleaning');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_locations', fn (Blueprint $table) => $table->dropColumn('allows_money_transfer'));
        Schema::table('register_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_by');
            $table->dropColumn(['collected_amount', 'collected_at']);
        });
        Schema::dropIfExists('cash_on_hand_movements');

        if (DB::getDriverName() === 'mysql') {
            DB::table('wallet_cash_movements')->where('kind', 'to_cash_on_hand')->update(['kind' => 'owner_draw']);
            DB::table('wallet_cash_movements')->where('kind', 'from_cash_on_hand')->update(['kind' => 'owner_transfer_in']);
            DB::statement('ALTER TABLE wallet_cash_movements MODIFY kind ENUM('.self::KINDS.') NOT NULL');
        }
    }
};
