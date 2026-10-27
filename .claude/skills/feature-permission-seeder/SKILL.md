---
name: feature-permission-seeder
description: Use whenever a new feature, page or functionality adds routes that need permissions (a new `Route::...->name('x.y')` under routes/domains.php or any permission-checked route). Creates a dedicated `<Feature>PermissionSeeder` so production can get the permissions with one `db:seed` command, instead of putting permission code in a migration.
---

# Feature permission seeder

Every new feature with routes gets its **own permission seeder**. Never create permissions inside a migration: production runs `migrate` and the seeder as two separate, explicit steps, and a seeder is safe to re-run.

Reference implementations: `database/seeders/SalesImportPermissionSeeder.php`, `PurchasingPermissionSeeder.php`, `FinancePermissionSeeder.php`. The shared logic is in `database/seeders/Concerns/SeedsFeaturePermissions.php`.

## Steps

1. **List the routes.** Permission `route_name` is the route name minus the `domains.` prefix (e.g. `domains.sales-import.store` -> `sales-import.store`). Include every route of the feature (index, template, preview, store, ...).

2. **Register the module** in `PermissionModuleSeeder::modules()` (`name` = the route prefix, plus `display_name`, `icon`, `description`, `sort_order`, `is_active`).

3. **Create the seeder** `database/seeders/<Feature>PermissionSeeder.php`:
   ```php
   class XPermissionSeeder extends Seeder
   {
       use SeedsFeaturePermissions;

       public const ROUTES = ['x.index', 'x.store'];
       public const ROLES = ['super admin', 'admin', 'manager']; // narrow or widen on purpose

       public function run(): void
       {
           $this->seedFeaturePermissions('X', ['x'], self::ROUTES, self::ROLES);
       }
   }
   ```
   Put the production command in the class docblock: `php artisan db:seed --class=XPermissionSeeder --force`.

4. **Keep fresh installs in sync:** add the same route names to the master `$permissions` list and to the matching role lists (admin, manager, ...) in `RolePermissionSeeder`.

5. **Migration = schema only.** If the feature needs tables/columns, the migration must not create permissions or modules. Mention the seeder command in the migration docblock.

6. **Test it** in `tests/Feature/FeaturePermissionSeedersTest.php`:
   - a route-coverage test (every `domains.x.*` route is in `ROUTES`, so a new route fails the test until the seeder is updated),
   - a test that default roles get the permissions, other roles (cashier) do not, and seeding twice changes nothing.
   Run tests only against `techiko_pos_test` (`--env=testing`; confirm `.env.testing` first, tests wipe the DB).

7. **Tell the user the deploy steps** in the final message:
   ```
   php artisan migrate --force
   php artisan db:seed --class=<Feature>PermissionSeeder --force
   ```

## Rules
- The seeder only adds; never remove or rename existing permissions.
- Decide the default roles deliberately (sensitive actions like imports, voids or money movement: managers and above only) and say which roles you chose.
- Do not commit unless the user asks.
