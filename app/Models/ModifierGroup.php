<?php

namespace App\Models;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A set of options picked when a product is rung up: Size, Sugar level, Add-ons… */
class ModifierGroup extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'is_required' => 'boolean',
        'max_select' => 'integer',
        'sort_order' => 'integer',
    ];

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeModifiers(): HasMany
    {
        return $this->modifiers()->where('is_active', true);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_group')->withPivot('sort_order');
    }

    /** How many options may be picked: one for a single choice, else max_select (no limit when null). */
    public function maxPicks(): ?int
    {
        return $this->selection === 'single' ? 1 : $this->max_select;
    }
}
