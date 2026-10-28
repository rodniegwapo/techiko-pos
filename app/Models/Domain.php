<?php

namespace App\Models;

use App\Traits\Searchable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
        'subscription_ends_at' => 'date:Y-m-d',
        'locked_at' => 'datetime',
        'vip_at' => 'datetime',
        'ai_monthly_credits_override' => 'integer',
    ];

    /**
     * Made VIP by a super admin: every premium feature and limit, unlimited AI credits, no
     * subscription warnings, never locked, nothing to pay.
     */
    public function isVip(): bool
    {
        return $this->vip_at !== null;
    }

    /** Locked by a super admin for not paying: its users only see the "on hold" page. A VIP never is. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null && ! $this->isVip();
    }

    /** Days until the subscription ends (0 on the last day, negative once it has ended), or null without an end date. */
    public function subscriptionEndsInDays(): ?int
    {
        return $this->subscription_ends_at
            ? (int) today()->diffInDays($this->subscription_ends_at->copy()->startOfDay(), false)
            : null;
    }

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
    /** Users belong to an organization by its slug (users.domain); users.domain_id is unused. */
    public function users()
    {
        return $this->hasMany(User::class, 'domain', 'name_slug');
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

    /** Its subscription payments (see SubscriptionPaymentService). */
    public function subscriptionPayments()
    {
        return $this->hasMany(SubscriptionPayment::class, 'domain', 'name_slug');
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

    /**
     * Features the frontend asks about (shared as domainFeatures). All but "ai" are paid: a plan
     * includes some of them (ServiceTier::features; the free plan none), and a super admin can
     * still give Finance to one organization.
     */
    public const FEATURES = ['sales_history_unlimited', 'multi_location', 'advanced_inventory', 'expenses', 'finance', 'ai', 'dine_in'];

    /** Features a plan can include (ServiceTier::features). */
    public const PLAN_FEATURES = ['sales_history_unlimited', 'multi_location', 'advanced_inventory', 'expenses', 'finance', 'dine_in'];

    /** Features a super admin can turn on for one organization outside its plan. */
    public const OVERRIDABLE_FEATURES = ['finance'];

    /** Days of sales history an organization without sales_history_unlimited can look back on. */
    public const FREE_SALES_HISTORY_DAYS = 31;

    /**
     * Finance includes its AI explanations. "ai" is the AI assistant in the chat drawer: every plan
     * has it, limited only by the month's AI credits (AiCreditService; a 0-credit override turns it off).
     */
    public function hasFeature(string $key): bool
    {
        if ($key === 'ai') {
            return true;
        }

        if (! in_array($key, self::FEATURES, true)) {
            return false;
        }

        // A VIP organization has everything.
        if ($this->isVip()) {
            return true;
        }

        $on = fn (string $k) => data_get($this->settings, "features.{$k}") === true;
        // Expenses used to come with Finance, so an organization given Finance keeps them.
        $given = $key === 'expenses' ? $on('finance') : (in_array($key, self::OVERRIDABLE_FEATURES, true) && $on($key));

        if ($given) {
            return true;
        }

        // Until plans are enforced, everything except the given features stays open as before.
        if (! config('features.plan_gating', false)) {
            return ! in_array($key, [...self::OVERRIDABLE_FEATURES, 'expenses'], true);
        }

        return in_array($key, $this->currentServiceTier?->features ?? [], true);
    }

    /** @return array<string, bool> keyed by Domain::FEATURES */
    public function features(): array
    {
        return collect(self::FEATURES)->mapWithKeys(fn ($k) => [$k => $this->hasFeature($k)])->all();
    }

    /** When true, the POS product list leaves out tracked products with nothing available at the store. */
    public function salesHidesOutOfStock(): bool
    {
        $s = $this->settings['sales'] ?? [];

        return (bool) ($s['hide_out_of_stock'] ?? false);
    }

    /**
     * What the business sells. It only presets the product form (useBusinessType.js: track stock,
     * sold type, which sections show); it never locks a feature or changes saved products.
     */
    public const BUSINESS_TYPES = ['retail', 'food', 'grocery', 'services', 'other'];

    /** The business type (Settings → Business type); retail, today's form, when none was picked. */
    public function businessType(): string
    {
        $type = $this->settings['sales']['business_type'] ?? null;

        return in_array($type, self::BUSINESS_TYPES, true) ? $type : 'retail';
    }

    /**
     * The last change to the business type or "Show all menu items" made on Settings: who, when, and
     * the type before it. Null when it was only set at signup, or never.
     *
     * @return array{by: string, at: string, from: ?string, to: string}|null
     */
    public function businessTypeChange(): ?array
    {
        $sales = $this->settings['sales'] ?? [];
        if (empty($sales['business_type_changed_at'])) {
            return null;
        }

        return [
            'by' => (string) ($sales['business_type_changed_by'] ?? ''),
            'at' => (string) $sales['business_type_changed_at'],
            'from' => in_array($sales['business_type_previous'] ?? null, self::BUSINESS_TYPES, true) ? $sales['business_type_previous'] : null,
            'to' => $this->businessType(),
        ];
    }

    /** "Show all menu items": the sidebar keeps the pages the business type would tidy away. */
    public function showsAllMenu(): bool
    {
        return (bool) ($this->settings['sales']['show_all_menu'] ?? false);
    }

    /**
     * Parts of the app the business already uses, so the sidebar keeps them whatever its type:
     * tables (a store with dine-in on, or tables set up) and stock (a product that tracks it).
     *
     * @return array{dining_tables: bool, stock: bool}
     */
    public function menuInUse(): array
    {
        return [
            'dining_tables' => InventoryLocation::query()->forDomain($this->name_slug)->where('dine_in', true)->exists()
                || DiningTable::query()->whereIn('location_id', InventoryLocation::query()->forDomain($this->name_slug)->select('id'))->exists(),
            'stock' => Product\Product::query()->where('domain', $this->name_slug)->where('track_inventory', true)->exists(),
        ];
    }

    /** Someone picked the business type; until then it reads as retail and admins are asked to pick it. */
    public function hasChosenBusinessType(): bool
    {
        return in_array($this->settings['sales']['business_type'] ?? null, self::BUSINESS_TYPES, true);
    }

    /** A cashier may start a new shift while their last one waits for a manager's review (on unless the store turns it off). */
    public function allowsNewShiftWhileReview(): bool
    {
        $s = $this->settings['sales'] ?? [];

        return (bool) ($s['allow_new_shift_while_review'] ?? true);
    }
}
