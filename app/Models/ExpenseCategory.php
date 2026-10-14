<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * operating: day-to-day running costs (rent, salaries…); other: one-off or non-operating costs
     * (interest, losses), shown below operating profit on the income statement.
     */
    public const TYPES = ['operating', 'other'];

    /** Created for a business the first time it opens Expenses. */
    public const DEFAULTS = [
        'Rent',
        'Salaries & Wages',
        'Utilities',
        'Supplies',
        'Repairs & Maintenance',
        'Transportation & Delivery',
        'Marketing',
        'Bank & Card Fees',
        'Taxes & Permits',
        'Other',
    ];

    public function scopeForDomain($query, string $domainSlug)
    {
        return $query->where('domain', $domainSlug);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public static function ensureDefaults(string $domainSlug): void
    {
        if (static::query()->forDomain($domainSlug)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $name) {
            static::query()->firstOrCreate(
                ['domain' => $domainSlug, 'name' => $name],
                ['is_default' => true, 'is_active' => true],
            );
        }
    }
}
