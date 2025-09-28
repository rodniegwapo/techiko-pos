<?php

namespace App\Console\Commands;

use App\Models\StockAdjustment;
use Illuminate\Console\Command;

class PopulateAdjustmentNumbers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'adjustments:populate-numbers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate missing adjustment numbers for existing stock adjustments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Populating missing adjustment numbers...');

        $adjustments = StockAdjustment::whereNull('adjustment_number')
            ->orWhere('adjustment_number', '')
            ->get();

        if ($adjustments->isEmpty()) {
            $this->info('No adjustments found with missing numbers.');
            return;
        }

        $count = 0;
        foreach ($adjustments as $adjustment) {
            $adjustment->adjustment_number = StockAdjustment::generateAdjustmentNumber();
            $adjustment->saveQuietly();
            $count++;
        }

        $this->info("Successfully populated {$count} adjustment numbers.");
    }
}