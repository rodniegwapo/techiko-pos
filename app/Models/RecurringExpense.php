<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringExpense extends Model
{
    protected $guarded = [];

    public const FREQUENCIES = ['monthly', 'weekly'];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'next_run_date' => 'date',
        'is_active' => 'boolean',
        'day_of_month' => 'integer',
        'day_of_week' => 'integer',
    ];

    public function scopeForDomain($query, string $domainSlug)
    {
        return $query->where('domain', $domainSlug);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** First run on or after $from. */
    public function firstRunOnOrAfter(CarbonInterface $from): Carbon
    {
        $from = Carbon::parse($from)->startOfDay();

        if ($this->frequency === 'weekly') {
            $date = $from->copy();
            while ($date->dayOfWeek !== $this->day_of_week) {
                $date->addDay();
            }

            return $date;
        }

        $date = $this->dayInMonth($from);

        return $date->lt($from) ? $this->dayInMonth($from->copy()->startOfMonth()->addMonth()) : $date;
    }

    /** The run after $date. */
    public function runAfter(CarbonInterface $date): Carbon
    {
        $date = Carbon::parse($date)->startOfDay();

        return $this->frequency === 'weekly'
            ? $date->copy()->addWeek()
            : $this->dayInMonth($date->copy()->startOfMonth()->addMonth());
    }

    /** day_of_month in that month, clamped so "31st" means the last day of shorter months. */
    private function dayInMonth(CarbonInterface $month): Carbon
    {
        $month = Carbon::parse($month)->startOfMonth();

        return $month->copy()->day(min($this->day_of_month, $month->daysInMonth));
    }

    public function scheduleLabel(): string
    {
        if ($this->frequency === 'weekly') {
            return 'Weekly on '.Carbon::create()->startOfWeek(Carbon::SUNDAY)->addDays($this->day_of_week)->format('l');
        }

        return 'Monthly on day '.$this->day_of_month.($this->day_of_month > 28 ? ' (or the last day)' : '');
    }
}
