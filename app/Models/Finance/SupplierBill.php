<?php

namespace App\Models\Finance;

use App\Models\ExpenseCategory;
use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What a supplier billed the business, and how much of it has been paid. */
class SupplierBill extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'bill_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    protected $appends = ['remaining', 'status'];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    /** Bills with something still to pay. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereColumn('paid_amount', '<', 'amount');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_date')->where('due_date', '<', today()->toDateString());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderBy('payment_date')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRemainingAttribute(): float
    {
        return round(max(0, (float) $this->amount - (float) $this->paid_amount), 2);
    }

    /** unpaid, partial, paid or overdue. */
    public function getStatusAttribute(): string
    {
        if ($this->remaining <= 0) {
            return 'paid';
        }
        if ($this->due_date !== null && $this->due_date->lt(today())) {
            return 'overdue';
        }

        return (float) $this->paid_amount > 0 ? 'partial' : 'unpaid';
    }

    /** Recomputes what has been paid from the payments on record. */
    public function refreshPaid(): void
    {
        $paid = round((float) $this->payments()->sum('amount'), 2);
        $this->forceFill([
            'paid_amount' => $paid,
            'paid_at' => $paid >= (float) $this->amount ? ($this->paid_at ?? now()) : null,
        ])->save();
    }
}
