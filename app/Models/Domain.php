<?php

namespace App\Models;

use App\Traits\Searchable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use LucaLongo\Licensing\Models\License;

class Domain extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $searchable = [
        'name',
        'name_slug',
        'country_code',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'subscription_started_at' => 'datetime',
    ];

    protected $attributes = [
        'timezone' => 'Asia/Manila',
        'country_code' => 'PH',
        'currency_code' => 'PHP',
        'date_format' => 'Y-m-d',
        'time_format' => '12h',
        'language_code' => 'en',
        'is_active' => true,
    ];

    /**
     * Mutator: ensure human-readable name is saved and keep name_slug in sync when appropriate.
     */
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = $value;

        // If slug is empty (new record) keep it in sync with name
        if (! $this->exists || empty($this->attributes['name_slug'])) {
            $this->attributes['name_slug'] = Str::slug($value);
        }
    }

    /**
     * Mutator: normalize slug if explicitly provided (e.g., edited in UI).
     */
    public function setNameSlugAttribute($value)
    {
        $this->attributes['name_slug'] = Str::slug($value ?: ($this->attributes['name'] ?? ''));
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName()
    {
        return 'name_slug';
    }

    /**
     * Get the users for this domain.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Software licenses issued to this organization (Laravel Licensing).
     */
    public function licenses(): MorphMany
    {
        return $this->morphMany(License::class, 'licensable');
    }

    /**
     * Get the customers for this domain.
     */
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Get the products for this domain.
     */
    public function products()
    {
        return $this->hasMany(Product\Product::class);
    }

    /**
     * Get the sales for this domain.
     */
    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function currentServiceTier()
    {
        return $this->belongsTo(ServiceTier::class, 'current_service_tier_id');
    }

    /**
     * Get the inventory locations for this domain.
     */
    public function inventoryLocations()
    {
        return $this->hasMany(InventoryLocation::class);
    }

    /**
     * Get current time in domain's timezone.
     */
    public function now()
    {
        return Carbon::now($this->timezone);
    }

    /**
     * Format date according to domain preferences.
     */
    public function formatDate($date)
    {
        return Carbon::parse($date)->setTimezone($this->timezone)
            ->format($this->date_format);
    }

    /**
     * Format time according to domain preferences.
     */
    public function formatTime($time)
    {
        return Carbon::parse($time)->setTimezone($this->timezone)
            ->format($this->time_format === '12h' ? 'g:i A' : 'H:i');
    }

    /**
     * Get currency symbol.
     */
    public function getCurrencySymbol()
    {
        $symbols = [
            'USD' => '$',
            'JPY' => '¥',
            'PHP' => '₱',
            'EUR' => '€',
            'GBP' => '£',
        ];

        return $symbols[$this->currency_code] ?? $this->currency_code;
    }

    /**
     * Format currency amount.
     */
    public function formatCurrency($amount)
    {
        return $this->getCurrencySymbol().number_format($amount, 2);
    }

    /**
     * Scope to get only active domains.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Find domain by slug.
     */
    public static function findBySlug($slug)
    {
        return static::where('name_slug', $slug)->active()->first();
    }

    /**
     * Sales VAT options from domain settings (defaults for backward compatibility).
     *
     * @return array{apply_vat_automatically: bool, vat_rate_percent: float, vat_pricing_mode: 'exclusive'|'inclusive'}
     */
    public function salesVatSettings(): array
    {
        $s = $this->settings['sales'] ?? [];

        $mode = $s['vat_pricing_mode'] ?? 'exclusive';
        if (! in_array($mode, ['exclusive', 'inclusive'], true)) {
            $mode = 'exclusive';
        }

        return [
            'apply_vat_automatically' => (bool) ($s['apply_vat_automatically'] ?? false),
            'vat_rate_percent' => (float) ($s['vat_rate_percent'] ?? 12),
            'vat_pricing_mode' => $mode,
        ];
    }

    /**
     * When true: sales may deduct below available stock; reconcile via adjustments.
     * When false (default when unset): checkout rejects insufficient stock at the sale's inventory location.
     */
    public function salesAllowsOverselling(): bool
    {
        $s = $this->settings['sales'] ?? [];

        return (bool) ($s['allow_overselling'] ?? false);
    }
}
