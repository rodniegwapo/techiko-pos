<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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

    /** @return list<string> */
    public static function billingHiddenSlugs(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $slug): string => strtolower((string) $slug),
            config('manual_billing.billing_hidden_tier_slugs', [])
        ))));
    }

    public static function isHiddenFromBilling(self|string $tier): bool
    {
        $slug = $tier instanceof self ? (string) $tier->slug : (string) $tier;

        return in_array(strtolower($slug), self::billingHiddenSlugs(), true);
    }

    /** @param Builder|\Illuminate\Database\Eloquent\Builder $query */
    public static function constrainVisibleOnBillingTierPicker($query): void
    {
        $hidden = self::billingHiddenSlugs();

        if ($hidden !== []) {
            $query->whereNotIn(DB::raw('LOWER(slug)'), $hidden);
        }
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
