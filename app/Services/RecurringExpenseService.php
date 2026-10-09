<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\RecurringExpense;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Turns due recurring templates into expenses. Safe to run any number of times: each run date is
 * booked at most once (unique recurring_expense_id + expense_date). It runs from the daily schedule
 * and also when Expenses or the P&L are opened, since a cron isn't guaranteed to be set up.
 */
class RecurringExpenseService
{
    public function __construct(private ExpenseService $expenses) {}

    /** @return int number of expenses created */
    public function generateDue(?string $domainSlug = null): int
    {
        $created = 0;
        $today = now()->startOfDay();

        $due = RecurringExpense::query()
            ->where('is_active', true)
            ->whereDate('next_run_date', '<=', $today)
            ->when($domainSlug, fn ($q) => $q->forDomain($domainSlug))
            ->get();

        foreach ($due as $template) {
            $created += $this->catchUp($template, $today);
        }

        return $created;
    }

    private function catchUp(RecurringExpense $template, $today): int
    {
        $created = 0;
        $runDate = $template->next_run_date->copy();

        while ($runDate->lte($today)) {
            if ($template->end_date && $runDate->gt($template->end_date)) {
                $template->is_active = false;
                break;
            }

            if ($this->book($template, $runDate)) {
                $created++;
            }
            $runDate = $template->runAfter($runDate);
        }

        $template->next_run_date = $runDate;
        if ($template->end_date && $runDate->gt($template->end_date)) {
            $template->is_active = false;
        }
        $template->save();

        return $created;
    }

    private function book(RecurringExpense $template, $runDate): bool
    {
        $alreadyBooked = Expense::withTrashed()
            ->where('recurring_expense_id', $template->id)
            ->whereDate('expense_date', $runDate)
            ->exists();
        if ($alreadyBooked) {
            return false;
        }

        $data = [
            'domain' => $template->domain,
            'location_id' => $template->location_id,
            'expense_category_id' => $template->expense_category_id,
            'amount' => $template->amount,
            'expense_date' => $runDate->toDateString(),
            'description' => $template->description,
            'payee' => $template->payee,
            'payment_method' => $template->payment_method,
            'recurring_expense_id' => $template->id,
            'user_id' => $template->user_id,
        ];

        try {
            $this->expenses->create($data);
        } catch (ValidationException $e) {
            // The cash drawer for that day is already closed. Still book the cost, as "other", so the
            // P&L is complete without rewriting a counted drawer; staff can adjust it afterwards.
            Log::warning('Recurring expense booked outside the wallet ledger', [
                'recurring_expense_id' => $template->id,
                'expense_date' => $runDate->toDateString(),
                'errors' => $e->errors(),
            ]);
            $this->expenses->create(['payment_method' => 'other', 'notes' => 'Cash drawer was closed for this date; not recorded in the wallet ledger.'] + $data);
        }

        return true;
    }
}
