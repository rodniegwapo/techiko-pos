<?php

namespace App\Models\Product;

use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\LocationProduct;
use App\Models\ModifierGroup;
use App\Models\ProductInventory;
use App\Models\SaleItem;
use App\Support\CostVisibility;
use App\Traits\Searchable;
use Database\Factories\Product\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasFactory, Searchable;

    protected $guarded = [];

    /** Cost stays with those who keep the books (CostVisibility); cashiers get prices and stock. */
    public function toArray()
    {
        return [
            ...CostVisibility::strip(parent::toArray(), ['cost']),
            // How it is counted, for the POS and stock screens: "kg" and parts allowed, or "pc".
            'quantity_unit' => $this->quantityUnit(),
            'allows_decimal' => $this->allowsDecimal(),
        ];
    }

    /** Sold by weight or volume (Kilo, Liter): may be sold in parts, e.g. 1.5 kg. */
    public function allowsDecimal(): bool
    {
        return (bool) (ProductSoldType::rules()[strtolower((string) $this->sold_type)]['allows_decimal'] ?? false);
    }

    /** The unit a quantity of this product is shown in: kg, L, pc, box… */
    public function quantityUnit(): string
    {
        return ProductSoldType::rules()[strtolower((string) $this->sold_type)]['unit'] ?? 'pc';
    }

    protected $searchable = [
        'price',
        'cost',
        'name',
        'SKU',
        'barcode',
        'category.name',
    ];

    protected $casts = [
        'track_inventory' => 'boolean',
        'reorder_level' => 'decimal:2',
        'max_stock_level' => 'decimal:2',
        'unit_weight' => 'decimal:3',
    ];

    protected static function newFactory()
    {
        return ProductFactory::new();
    }

    // Remove domain relationship - now using domain string column
    // public function domain()
    // {
    //     return $this->belongsTo(\App\Models\Domain::class);
    // }

    // Add scope for easy domain filtering
    public function scopeForDomain($query, $domain)
    {
        return $query->where('domain', $domain);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get all locations where this product is available
     */
    public function locations()
    {
        return $this->belongsToMany(InventoryLocation::class, 'location_product', 'product_id', 'location_id')
            ->using(LocationProduct::class)
            ->withPivot('is_active', 'price')
            ->withTimestamps();
    }

    /**
     * Get only active locations where this product is available
     */
    public function activeLocations()
    {
        return $this->belongsToMany(InventoryLocation::class, 'location_product', 'product_id', 'location_id')
            ->using(LocationProduct::class)
            ->wherePivot('is_active', true)
            ->withPivot('is_active', 'price')
            ->withTimestamps();
    }

    /** The store's own price for this product, or null when it sells at the product's price. */
    public function storePriceAt(?int $locationId): ?float
    {
        if (! $locationId) {
            return null;
        }
        $price = DB::table('location_product')
            ->where('product_id', $this->id)
            ->where('location_id', $locationId)
            ->value('price');

        return $price !== null ? (float) $price : null;
    }

    /** What this product sells for at a store: the store's own price, else the product's price. */
    public function priceAt(?int $locationId): float
    {
        return $this->storePriceAt($locationId) ?? (float) $this->price;
    }

    /**
     * Store prices for many products at once (catalog lists): [product_id => price] for those with one.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, float>
     */
    public static function storePricesAt(?int $locationId, iterable $productIds): array
    {
        if (! $locationId) {
            return [];
        }

        return DB::table('location_product')
            ->where('location_id', $locationId)
            ->whereIn('product_id', collect($productIds)->all())
            ->whereNotNull('price')
            ->pluck('price', 'product_id')
            ->map(fn ($p) => (float) $p)
            ->all();
    }

    /** Shows each product in the list at its price at this store (in memory, nothing saved). */
    public static function applyStorePrices(iterable $products, ?int $locationId): void
    {
        $products = collect($products);
        $prices = self::storePricesAt($locationId, $products->pluck('id'));
        foreach ($products as $product) {
            if (array_key_exists($product->id, $prices)) {
                $product->setAttribute('base_price', $product->price);
                $product->setAttribute('price', $prices[$product->id]);
            }
        }
    }

    /**
     * @deprecated Use locations() or activeLocations() instead
     */
    public function location()
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    public function saleitems()
    {
        return $this->hasMany(SaleItem::class);
    }

    /** The option groups picked when this product is rung up (Size, Add-ons…), in their order. */
    public function modifierGroups()
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')
            ->withPivot('sort_order')
            ->orderBy('product_modifier_group.sort_order')
            ->orderBy('modifier_groups.id');
    }

    /**
     * Get the inventory records for this product
     */
    public function inventories()
    {
        return $this->hasMany(ProductInventory::class);
    }

    /**
     * Get the inventory movements for this product
     */
    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Get inventory for a specific location
     */
    public function inventoryAt(InventoryLocation $location)
    {
        return $this->inventories()->where('location_id', $location->id)->first();
    }

    /**
     * Get inventory for the default location
     */
    public function defaultInventory()
    {
        $defaultLocation = InventoryLocation::getDefault();

        return $defaultLocation ? $this->inventoryAt($defaultLocation) : null;
    }

    /**
     * Get total quantity across all locations
     */
    public function getTotalQuantityAttribute()
    {
        return $this->inventories()->sum('quantity_on_hand');
    }

    /**
     * Get total available quantity across all locations
     */
    public function getTotalAvailableQuantityAttribute()
    {
        return $this->inventories()->sum('quantity_available');
    }

    /**
     * Check if product is in stock at any location
     */
    public function isInStock($quantity = 1, ?InventoryLocation $location = null)
    {
        if (! $this->track_inventory) {
            return true; // Non-tracked items are always "in stock"
        }

        if ($location) {
            $inventory = $this->inventoryAt($location);

            return $inventory ? $inventory->isInStock($quantity) : false;
        }

        return $this->getTotalAvailableQuantityAttribute() >= $quantity;
    }

    /**
     * Check if product is low stock
     */
    public function isLowStock(?InventoryLocation $location = null)
    {
        if (! $this->track_inventory) {
            return false;
        }

        if ($location) {
            $inventory = $this->inventoryAt($location);

            return $inventory ? $inventory->isLowStock() : true;
        }

        return $this->getTotalAvailableQuantityAttribute() <= $this->reorder_level;
    }

    /**
     * Get stock status
     */
    public function getStockStatus(?InventoryLocation $location = null)
    {
        if (! $this->track_inventory) {
            return 'in_stock';
        }

        if ($location) {
            $inventory = $this->inventoryAt($location);

            return $inventory ? $inventory->getStockStatus() : 'out_of_stock';
        }

        $totalAvailable = $this->getTotalAvailableQuantityAttribute();

        if ($totalAvailable <= 0) {
            return 'out_of_stock';
        }

        if ($totalAvailable <= $this->reorder_level) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    /**
     * Scope for products that track inventory
     */
    public function scopeTracked($query)
    {
        return $query->where('track_inventory', true);
    }

    /**
     * Scope for products that are in stock
     */
    public function scopeInStock($query, ?InventoryLocation $location = null)
    {
        if ($location) {
            return $query->whereHas('inventories', function ($q) use ($location) {
                $q->where('location_id', $location->id)
                    ->where('quantity_available', '>', 0);
            });
        }

        return $query->whereHas('inventories', function ($q) {
            $q->where('quantity_available', '>', 0);
        });
    }

    /**
     * Scope for products that are low stock
     */
    public function scopeLowStock($query, ?InventoryLocation $location = null)
    {
        $query->where('track_inventory', true);

        if ($location) {
            return $query->whereHas('inventories', function ($q) use ($location) {
                $q->where('location_id', $location->id)
                    // The store's own low stock level, else the product's.
                    ->whereRaw('quantity_available <= COALESCE(product_inventory.location_reorder_level, products.reorder_level)');
            });
        }

        return $query->whereRaw('(SELECT SUM(quantity_available) FROM product_inventory WHERE product_id = products.id) <= products.reorder_level');
    }

    /**
     * Scope for products that are out of stock
     */
    public function scopeOutOfStock($query, ?InventoryLocation $location = null)
    {
        $query->where('track_inventory', true);

        if ($location) {
            return $query->whereHas('inventories', function ($q) use ($location) {
                $q->where('location_id', $location->id)
                    ->where('quantity_available', '<=', 0);
            });
        }

        return $query->whereRaw('(SELECT SUM(quantity_available) FROM product_inventory WHERE product_id = products.id) <= 0');
    }

    /**
     * Add product to a location
     */
    public function addToLocation(InventoryLocation $location, bool $isActive = true)
    {
        return $this->activeLocations()->syncWithoutDetaching([
            $location->id => ['is_active' => $isActive],
        ]);
    }

    /**
     * Remove product from a location
     */
    public function removeFromLocation(InventoryLocation $location)
    {
        return $this->activeLocations()->detach($location->id);
    }

    /**
     * Check if product is available at location
     */
    public function isAvailableAt(InventoryLocation $location): bool
    {
        return $this->activeLocations()->where('location_id', $location->id)->exists();
    }

    /**
     * Toggle product availability at location
     */
    public function toggleAtLocation(InventoryLocation $location): bool
    {
        $isCurrentlyActive = $this->activeLocations()->where('location_id', $location->id)->exists();

        if ($isCurrentlyActive) {
            $this->removeFromLocation($location);

            return false;
        } else {
            $this->addToLocation($location);

            return true;
        }
    }
}
