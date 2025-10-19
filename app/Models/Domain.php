<?php

namespace App\Models;

use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

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
        return $this->getCurrencySymbol() . number_format($amount, 2);
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
}