<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One subscription payment; it moved the organization's next payment date from period_start to period_end. */
class SubscriptionPayment extends Model
{
    public const METHOD_LABELS = [
        'bank_transfer' => 'Bank transfer',
        'gcash' => 'GCash',
        'paymongo' => 'Online (PayMongo)',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'months' => 'integer',
        'paid_on' => 'date:Y-m-d',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'previous_ends_at' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];

    public function serviceTier(): BelongsTo
    {
        return $this->belongsTo(ServiceTier::class);
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Payments that count (not voided). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeForDomain(Builder $query, string $domain): Builder
    {
        return $query->where('domain', $domain);
    }

    public function methodLabel(): string
    {
        return self::METHOD_LABELS[$this->method] ?? ucfirst(str_replace('_', ' ', $this->method));
    }

    /** What the history tables show. */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'paid_on' => $this->paid_on->toDateString(),
            'amount' => (float) $this->amount,
            'months' => $this->months,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'method' => $this->methodLabel(),
            'reference_number' => $this->reference_number,
            'bank_name' => $this->bank_name,
            'plan' => $this->serviceTier?->name,
            'note' => $this->note,
            'recorded_by' => $this->recordedByUser?->name,
            'voided' => $this->voided_at !== null,
        ];
    }
}
