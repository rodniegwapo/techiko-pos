<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * The product modifiers' (add-ons) permissions, in the Products module, for an existing database:
 * php artisan db:seed --class=ProductModifierPermissionSeeder --force
 *
 * Creates any missing permission and gives each default role the ones it lacks, like RolePermissionSeeder:
 * everyone who can view products can view modifiers; supervisors and above can change them.
 * It never removes anything. A test keeps ROUTES in step with the modifier-group routes.
 */
class ProductModifierPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = [
        'products.modifier-groups.index',
        'products.modifier-groups.store',
        'products.modifier-groups.update',
        'products.modifier-groups.destroy',
    ];

    public const VIEW_ROUTES = ['products.modifier-groups.index'];

    public const VIEW_ROLES = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];

    public const CHANGE_ROUTES = [
        'products.modifier-groups.store',
        'products.modifier-groups.update',
        'products.modifier-groups.destroy',
    ];

    public const CHANGE_ROLES = ['super admin', 'admin', 'manager', 'supervisor'];

    public function run(): void
    {
        $this->seedFeaturePermissions('Product modifiers (view)', ['products'], self::VIEW_ROUTES, self::VIEW_ROLES);
        $this->seedFeaturePermissions('Product modifiers (change)', ['products'], self::CHANGE_ROUTES, self::CHANGE_ROLES);
    }
}
