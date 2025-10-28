# Product-Location Many-to-Many Relationships

This document explains the new many-to-many relationship between products and locations, replacing the previous single `location_id` column approach.

## Overview

Products can now be available at multiple locations, providing better flexibility for inventory management across different stores, warehouses, and other locations.

## Database Structure

### Pivot Table: `location_product`
- `id` - Primary key
- `location_id` - Foreign key to `inventory_locations`
- `product_id` - Foreign key to `products`
- `is_active` - Boolean flag for active/inactive relationships
- `created_at` - Timestamp
- `updated_at` - Timestamp

## Model Relationships

### Product Model
```php
// Get all locations where product is available
$product->locations()

// Get only active locations
$product->activeLocations()

// Check if available at specific location
$product->isAvailableAt($location)

// Add product to location
$product->addToLocation($location, $isActive = true)

// Remove product from location
$product->removeFromLocation($location)

// Toggle availability at location
$product->toggleAtLocation($location)
```

### InventoryLocation Model
```php
// Get all products at location
$location->products()

// Get only active products
$location->activeProducts()
```

## Usage Examples

### Querying Products by Location
```php
// Get all products available at a specific location
$products = Product::whereHas('activeLocations', function($query) use ($locationId) {
    $query->where('location_id', $locationId);
})->get();

// Get products with their inventory at specific location
$products = Product::with(['inventories' => function($query) use ($locationId) {
    $query->where('location_id', $locationId);
}])->whereHas('activeLocations', function($query) use ($locationId) {
    $query->where('location_id', $locationId);
})->get();
```

### Managing Product-Location Relationships
```php
// Add a product to multiple locations
$product = Product::find(1);
$locations = InventoryLocation::whereIn('id', [1, 2, 3])->get();

foreach ($locations as $location) {
    $product->addToLocation($location);
}

// Remove product from a location
$product->removeFromLocation($location);

// Check availability
if ($product->isAvailableAt($location)) {
    // Product is available at this location
}

// Toggle availability
$wasAdded = $product->toggleAtLocation($location);
```

### Querying Locations by Product
```php
// Get all locations where a product is available
$locations = $product->activeLocations;

// Get all products at a location
$products = $location->activeProducts;
```

## Migration from Old System

The migration process automatically:
1. Creates the `location_product` pivot table
2. Migrates existing `products.location_id` data to the pivot table
3. Removes the `location_id` column from the products table

## Benefits

1. **Flexibility**: Products can be available at multiple locations
2. **Scalability**: Easy to add new locations without schema changes
3. **Data Integrity**: No data duplication, single source of truth
4. **Better Performance**: Optimized queries with proper indexing
5. **Future-Proof**: Easy to add location-specific product settings

## Backward Compatibility

The old `location()` relationship is marked as deprecated but still available for backward compatibility. New code should use `activeLocations()` instead.

## Inventory Management

The existing `ProductInventory` model continues to work as before, managing actual stock levels at each location. The new many-to-many relationship only controls product availability at locations, while inventory levels are still managed through the `product_inventory` table.
