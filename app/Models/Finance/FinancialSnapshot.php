<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;

/**
 * The balance sheet figures at the end of a day. Inventory, customer credit and cash are only
 * known "as of now", so these are what lets the Finance pages compare them over time.
 */
class FinancialSnapshot extends Model
{
    public const FIGURES = [
        'cash_in_drawer', 'accounts_balance', 'inventory_value', 'receivables', 'receivables_overdue',
        'fixed_assets', 'payables', 'loans', 'other_liabilities', 'total_assets', 'total_liabilities', 'net_worth',
    ];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'as_of_date' => 'date:Y-m-d',
        'cash_in_drawer' => 'float',
        'accounts_balance' => 'float',
        'inventory_value' => 'float',
        'receivables' => 'float',
        'receivables_overdue' => 'float',
        'fixed_assets' => 'float',
        'payables' => 'float',
        'loans' => 'float',
        'other_liabilities' => 'float',
        'total_assets' => 'float',
        'total_liabilities' => 'float',
        'net_worth' => 'float',
    ];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    /** Cash on hand: drawers plus bank and e-wallet accounts. */
    public function cashBalance(): float
    {
        return round($this->cash_in_drawer + $this->accounts_balance, 2);
    }

    /** The latest snapshot on or before a day. */
    public static function onOrBefore(string $domain, string $date): ?self
    {
        return static::query()->forDomain($domain)->where('as_of_date', '<=', $date)->orderByDesc('as_of_date')->first();
    }
}
