<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTier extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'max_products' => 'integer',
        'max_users' => 'integer',
    ];

    public function manualPaymentRequests(): HasMany
    {
        return $this->hasMany(ManualPaymentRequest::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
