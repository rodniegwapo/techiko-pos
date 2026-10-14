<?php

namespace App\Console\Commands;

use App\Services\RecurringExpenseService;
use Illuminate\Console\Command;

class GenerateRecurringExpenses extends Command
{
    protected $signature = 'expenses:generate-recurring {--domain= : Only this business (name slug)}';

    protected $description = 'Book recurring expenses (rent, wages…) that have fallen due';

    public function handle(RecurringExpenseService $service): int
    {
        $created = $service->generateDue($this->option('domain') ?: null);
        $this->info("Booked {$created} recurring expense(s).");

        return self::SUCCESS;
    }
}
