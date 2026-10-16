<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;

class ServiceTier extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'shown_on_billing' => true,
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
        'shown_on_billing' => 'boolean',
        'sort_order' => 'integer',
        'max_products' => 'integer',
        'max_users' => 'integer',
        'ai_monthly_credits' => 'integer',
        'features' => 'array',
    ];

    public function manualPaymentRequests(): HasMany
    {
        return $this->hasMany(ManualPaymentRequest::class);
    }

    /** Organizations can't pick it on the billing page (super admins set this on the Plans page). */
    public static function isHiddenFromBilling(self|string $tier): bool
    {
        $tier = $tier instanceof self ? $tier : self::query()->whereRaw('LOWER(slug) = ?', [strtolower($tier)])->first();

        return $tier !== null && ! $tier->shown_on_billing;
    }

    /** @param Builder|\Illuminate\Database\Eloquent\Builder $query */
    public static function constrainVisibleOnBillingTierPicker($query): void
    {
        $query->where('shown_on_billing', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
