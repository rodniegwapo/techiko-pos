<?php

namespace App\Models;

use App\Models\Product\Product;
use App\Support\BarcodeNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedProductSuggestion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot' => 'array',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (SharedProductSuggestion $model) {
            $model->barcode = BarcodeNormalizer::normalize($model->barcode);
        });
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForDomain(Builder $query, string $domainSlug): Builder
    {
        return $query->where('domain', $domainSlug);
    }

    public function submittedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'submitted_product_id');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
