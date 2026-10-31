<?php

namespace App\Console\Commands;

use App\Models\RegisterShift;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Support\Wallet\RegisterShiftService;
use Illuminate\Console\Command;

/**
 * Catch-up for shifts approved before a count difference went into the store's drawer on approval
 * (RegisterShiftService::bookCountDifference): books the line for each approved shift that came out
 * short or over and doesn't have one yet. Safe to run again: a shift whose line is there is
 * skipped. Run once after deploying:
 * php artisan wallet:book-shift-differences --dry-run   (lists what it would book)
 * php artisan wallet:book-shift-differences
 */
class BookShiftCountDifferences extends Command
{
    protected $signature = 'wallet:book-shift-differences {--dry-run : List what would be booked without changing anything}';

    protected $description = "Book approved shifts' count differences (short or over) into their store's drawer";

    public function handle(RegisterShiftService $shifts): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $total = 0;

        RegisterShift::query()
            ->where('status', RegisterShift::APPROVED)
            ->whereNotNull('variance')
            ->where('variance', '!=', 0)
            ->with(['user:id,name', 'location:id,name'])
            ->orderBy('id')
            ->each(function (RegisterShift $shift) use ($shifts, $dryRun, &$rows, &$total) {
                $booked = WalletCashMovement::query()
                    ->where('domain', $shift->domain)
                    ->where('location_id', $shift->location_id)
                    ->where('kind', 'adjustment')
                    ->where('notes', 'like', "Count difference on %shift #{$shift->id}")
                    ->exists();
                if ($booked) {
                    return;
                }

                $date = RegisterShiftService::drawerDateFor($shift);
                $rows[] = [$shift->id, $shift->domain, $shift->location?->name, $shift->user?->name, $shift->business_date->toDateString(), number_format((float) $shift->variance, 2), $date];
                $total++;
                if (! $dryRun) {
                    // Booked as by whoever approved it, or the cashier when that's unknown.
                    $by = User::query()->find($shift->reviewed_by ?: $shift->user_id);
                    if ($by) {
                        $shifts->bookCountDifference($shift, $by);
                    }
                }
            });

        if ($rows) {
            $this->table(['Shift', 'Business', 'Store', 'Cashier', 'Shift day', 'Difference', 'Into drawer on'], $rows);
        }
        $this->info($dryRun
            ? "{$total} shift(s) would get their count difference booked. Run without --dry-run to book them."
            : "Booked the count difference of {$total} shift(s).");

        return self::SUCCESS;
    }
}
