<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that are searchable.
     */
    protected $searchable = [
        'name',
        'display_name',
        'description',
    ];

    protected $casts = [
        'multiplier' => 'decimal:2',
        'spending_threshold' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    // Get tiers ordered by sort_order
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    // Get tier for a specific spending amount
    public static function getTierForSpending($amount)
    {
        return static::active()
            ->where('spending_threshold', '<=', $amount)
            ->orderBy('spending_threshold', 'desc')
            ->first();
    }

    // Get all active tiers as array for frontend
    public static function getActiveTiersArray()
    {
        return static::active()
            ->ordered()
            ->get()
            ->map(function ($tier) {
                return [
                    'id' => $tier->id,
                    'name' => $tier->name,
                    'display_name' => $tier->display_name,
                    'multiplier' => $tier->multiplier,
                    'spending_threshold' => $tier->spending_threshold,
                    'color' => $tier->color,
                    'description' => $tier->description,
                ];
            })
            ->toArray();
    }
}
