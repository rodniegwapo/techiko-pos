# Domain-Specific Default Locations

## Overview

This implementation allows each domain to have its own default inventory location, providing better domain isolation and user experience in multi-tenant scenarios.

## Key Features

- **Domain Isolation**: Each domain can have its own default location
- **Automatic Fallback**: If no default is set, the first active location is used
- **Data Integrity**: Only one default location per domain is allowed
- **Backward Compatibility**: Existing functionality continues to work

## Implementation Details

### Database Changes

- Added index on `['domain', 'is_default']` for better performance
- Migration ensures only one default per domain during setup

### Model Updates

#### `InventoryLocation` Model

```php
// Get default location for a specific domain
InventoryLocation::getDefault($domainSlug);

// Set a location as default for its domain
$location->setAsDefault();

// Scope for domain-specific defaults
InventoryLocation::defaultForDomain($domainSlug)->get();
```

### Helper Updates

#### `Helpers::getActiveLocation()`

Now uses domain-specific defaults:
1. Uses provided `location_id` if specified
2. Uses user's assigned `location_id` if available
3. Falls back to domain's default location
4. Falls back to first active location in domain

### Controller Updates

#### `InventoryLocationController::setDefault()`

- Now sets default only for the location's domain
- Automatically unsets any existing default for that domain
- Provides domain-specific success message

### Middleware Updates

#### `HandleInertiaRequests`

- `default_store` now returns domain-specific default
- Uses `getDefaultStore()` method for proper domain context

## Usage Examples

### Setting a Default Location

```php
// Set a location as default for its domain
$location = InventoryLocation::find(1);
$location->setAsDefault();
```

### Getting Domain Default

```php
// Get default for a specific domain
$defaultLocation = InventoryLocation::getDefault('jollibee-corp');

// Get default for domain object
$domain = Domain::find(1);
$defaultLocation = InventoryLocation::getDefault($domain);
```

### Getting Active Location

```php
// This now uses domain-specific defaults
$activeLocation = Helpers::getActiveLocation($domain);
```

## Benefits

1. **Better UX**: Users see relevant locations for their domain
2. **Data Integrity**: Prevents cross-domain location conflicts
3. **Scalability**: Supports multi-tenant architecture
4. **Maintainability**: Centralized logic for default handling
5. **Performance**: Optimized queries with proper indexing

## Migration Notes

- Existing data is automatically fixed during migration
- Multiple defaults per domain are reduced to one
- Legacy locations without domain are handled gracefully

## Testing

The implementation has been tested with:
- Multiple domains with different default locations
- Setting new defaults and verifying uniqueness
- Fallback behavior when no default is set
- Domain isolation verification

## API Changes

### `POST /inventory-locations/{location}/set-default`

- Now sets default only for the location's domain
- Success message updated to reflect domain context
- Automatically handles domain-specific uniqueness

## Frontend Considerations

- Location selection components should show domain context
- Default location indicators should be domain-aware
- Location management UI should reflect domain isolation






