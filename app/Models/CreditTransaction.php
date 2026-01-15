<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

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

    // Scopes
    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeOverdue($query)
    {
        return $query->where('transaction_type', 'credit')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNull('paid_at');
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
    public function isOverdue(): bool
    {
        return $this->transaction_type === 'credit'
            && $this->due_date !== null
            && $this->due_date < now()
            && $this->paid_at === null;
    }

    public function markAsPaid(): void
    {
        $this->update([
            'paid_at' => now(),
        ]);
    }

    public function getDaysOverdue(): ?int
    {
        if (!$this->isOverdue()) {
            return null;
        }

        return now()->diffInDays($this->due_date);
    }
}
