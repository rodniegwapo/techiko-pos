<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app's clock moves from UTC to Asia/Manila (config/app.php), so business days start at
 * midnight in the Philippines instead of 8:00 AM. Every date-and-time already saved was written
 * on the UTC clock: this adds 8 hours to each, so old and new records line up.
 *
 * Dates without a time (business_date, movement_date) are left as they are. Columns the database
 * fills itself (DEFAULT CURRENT_TIMESTAMP, not set by the app) are skipped: the database server's
 * clock already gave them local time. One UPDATE per table moves all of its columns together.
 *
 * Back up the database before running this in production.
 */
return new class extends Migration
{
    private const HOURS = 8;

    public function up(): void
    {
        $this->shift('+');
        // The Philippines for now: every business is on Manila time (others are coming soon).
        DB::table('domains')->where(fn ($q) => $q->whereNull('timezone')->orWhere('timezone', '!=', 'Asia/Manila'))
            ->update(['timezone' => 'Asia/Manila']);
    }

    public function down(): void
    {
        $this->shift('-');
    }

    private function shift(string $sign): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $columns = collect(DB::select(
            "SELECT table_name AS t, column_name AS c, extra AS e
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND data_type IN ('datetime', 'timestamp')
              ORDER BY table_name, ordinal_position"
        ))
            // Filled by the database itself: already in the server's local time.
            ->reject(fn ($col) => str_contains(strtoupper((string) $col->e), 'DEFAULT_GENERATED'))
            ->groupBy('t');

        foreach ($columns as $table => $cols) {
            $sets = $cols->map(fn ($col) => sprintf(
                '`%1$s` = IF(`%1$s` IS NULL, NULL, `%1$s` %2$s INTERVAL %3$d HOUR)',
                $col->c,
                $sign,
                self::HOURS,
            ))->join(', ');

            DB::statement(sprintf('UPDATE `%s` SET %s', $table, $sets));
        }
    }
};
