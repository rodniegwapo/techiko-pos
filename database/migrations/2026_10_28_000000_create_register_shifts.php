<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cashier's own shift on the till (several can run at one store), with its count, the
     * difference from Expected, and whether a manager still has to review it. Sales and cash lines
     * remember the shift they were taken in. A reopen is logged with what the shift showed before,
     * and needs a reason again.
     *
     * Permissions come from the seeder: php artisan db:seed --class=RegisterShiftPermissionSeeder --force
     */
    public function up(): void
    {
        Schema::create('register_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->foreignId('location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->date('business_date');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->timestamp('opened_at');
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            // open | closed | needs_review | approved
            $table->string('status', 20)->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['domain', 'location_id', 'user_id', 'status'], 'register_shifts_user_status_index');
            $table->index(['domain', 'location_id', 'business_date'], 'register_shifts_day_index');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('register_shift_id')->nullable()->after('user_id')->constrained('register_shifts')->nullOnDelete();
        });

        Schema::table('wallet_cash_movements', function (Blueprint $table) {
            $table->foreignId('register_shift_id')->nullable()->after('user_id')->constrained('register_shifts')->nullOnDelete();
        });

        Schema::table('wallet_cash_count_submissions', function (Blueprint $table) {
            $table->foreignId('register_shift_id')->nullable()->after('reconciliation_id')->constrained('register_shifts')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        // Reopening needs a reason again; reopens logged without one keep a placeholder.
        DB::table('wallet_shift_reopen_logs')->whereNull('reason')->update(['reason' => '(no reason given)']);
        Schema::table('wallet_shift_reopen_logs', function (Blueprint $table) {
            $table->string('reason')->nullable(false)->change();
            $table->foreignId('register_shift_id')->nullable()->after('reconciliation_id')->constrained('register_shifts')->nullOnDelete();
            // What the shift showed when it was reopened: count, Expected, difference, status.
            $table->json('before')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_shift_reopen_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('register_shift_id');
            $table->dropColumn('before');
            $table->string('reason')->nullable()->change();
        });
        Schema::table('wallet_cash_count_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('register_shift_id');
            $table->dropColumn('approved_at');
        });
        Schema::table('wallet_cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('register_shift_id');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('register_shift_id');
        });
        Schema::dropIfExists('register_shifts');
    }
};
