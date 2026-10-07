<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditTransaction extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

    protected $appends = ['remaining', 'is_overdue'];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The schedule of a charge paid in installments (empty for a single due date). */
    public function installments(): HasMany
    {
        return $this->hasMany(CreditInstallment::class)->orderBy('seq');
    }

    // Scopes
    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Unpaid charges with something late: past their due date, or, when paid in installments,
     * with an unpaid installment past its own due date.
     */
    public function scopeOverdue($query)
    {
        $today = today()->toDateString();

        return $query->where('transaction_type', 'credit')
            ->whereNull('paid_at')
            ->where(function ($q) use ($today) {
                $q->where(function ($single) use ($today) {
                    $single->whereDoesntHave('installments')
                        ->whereNotNull('due_date')
                        ->where('due_date', '<', $today);
                })->orWhereHas('installments', function ($i) use ($today) {
                    $i->whereNull('paid_at')->where('due_date', '<', $today);
                });
            });
    }

    public function scopeCredit($query)
    {
        return $query->where('transaction_type', 'credit');
    }

    public function scopePayment($query)
    {
        return $query->where('transaction_type', 'payment');
    }

    public function scopeForDomain($query, $domain)
    {
        return $query->where('domain', $domain);
    }

    // Methods

    /** What is still owed on a charge (0 for payments, refunds and adjustments). */
    public function getRemainingAttribute(): float
    {
        if ($this->transaction_type !== 'credit' || $this->paid_at !== null) {
            return 0.0;
        }

        return round(max(0, (float) $this->amount - (float) $this->paid_amount), 2);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->isOverdue();
    }

    /** What is late on this charge: the unpaid part of late installments, or all that's left once past due. */
    public function overdueAmount(): float
    {
        if (! $this->isOverdue()) {
            return 0.0;
        }

        $installments = $this->relationLoaded('installments') ? $this->installments : $this->installments()->get();
        if ($installments->isEmpty()) {
            return $this->remaining;
        }

        return round($installments->filter->is_overdue->sum(fn ($i) => $i->remaining), 2);
    }

    public function isOverdue(): bool
    {
        if ($this->transaction_type !== 'credit' || $this->paid_at !== null) {
            return false;
        }

        $installments = $this->relationLoaded('installments') ? $this->installments : $this->installments()->get();
        if ($installments->isNotEmpty()) {
            return $installments->contains(fn ($i) => $i->is_overdue);
        }

        return $this->due_date !== null && $this->due_date->lt(today());
    }

    public function markAsPaid(): void
    {
        $this->update([
            'paid_amount' => $this->amount,
            'paid_at' => now(),
        ]);
    }

    public function getDaysOverdue(): ?int
    {
        if (! $this->isOverdue()) {
            return null;
        }

        $installments = $this->relationLoaded('installments') ? $this->installments : $this->installments()->get();
        $since = $installments->isNotEmpty()
            ? $installments->filter->is_overdue->min('due_date')
            : $this->due_date;

        return (int) $since->diffInDays(today());
    }
}
