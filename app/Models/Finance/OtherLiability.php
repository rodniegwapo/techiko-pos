<?php

namespace App\Models\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the business owes besides supplier bills and loans: taxes due, a customer's deposit,
 * wages not yet paid. It counts as owed from the day it arose until the day it is settled.
 */
class OtherLiability extends Model
{
    public const CATEGORIES = ['tax', 'customer_deposit', 'wages', 'other'];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'incurred_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'settled_date' => 'date:Y-m-d',
    ];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    /** Owed on a day: arisen by then and not yet settled. */
    public function scopeOutstandingOn(Builder $query, string $date): Builder
    {
        return $query->where('incurred_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('settled_date')->orWhere('settled_date', '>', $date));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
