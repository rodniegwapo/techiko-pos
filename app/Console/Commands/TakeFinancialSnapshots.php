<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Finance\FinancialSnapshotService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Saves today's balance sheet figures for every business, so the Finance pages can compare
 * inventory, customer credit and cash over time. Scheduled shortly before midnight.
 */
class TakeFinancialSnapshots extends Command
{
    protected $signature = 'finance:snapshot {--domain= : Only this business (its slug)}';

    protected $description = "Save today's balance sheet figures for each business";

    public function handle(FinancialSnapshotService $snapshots): int
    {
        $domains = Domain::query()
            ->when($this->option('domain'), fn ($q, $slug) => $q->where('name_slug', $slug))
            ->pluck('name_slug');

        foreach ($domains as $slug) {
            try {
                $snapshots->capture($slug);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$slug}: {$e->getMessage()}");
            }
        }

        $this->info('Snapshots saved for '.$domains->count().' business(es).');

        return self::SUCCESS;
    }
}
