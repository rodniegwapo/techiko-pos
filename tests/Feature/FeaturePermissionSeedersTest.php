<?php

namespace Tests\Feature;

use App\Models\Permission;
use Database\Seeders\AssistantPermissionSeeder;
use Database\Seeders\CashOnHandPermissionSeeder;
use Database\Seeders\DineInPermissionSeeder;
use Database\Seeders\FinancePermissionSeeder;
use Database\Seeders\ProductModifierPermissionSeeder;
use Database\Seeders\PurchasingPermissionSeeder;
use Database\Seeders\RegisterShiftPermissionSeeder;
use Database\Seeders\SalesHistoryPermissionSeeder;
use Database\Seeders\SalesImportPermissionSeeder;
use Database\Seeders\ShiftReopenPermissionSeeder;
use Database\Seeders\TimeclockPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Finance and AI assistant permission seeders, which are run on production databases: they
 * cover every route behind the feature, add only what is missing, and leave roles' other
 * permissions (and ones taken away on the Roles page) alone.
 */
class FeaturePermissionSeedersTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> The route permissions behind a domain.feature:<x> middleware. */
    private function routesBehind(string $feature): array
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->getName() && in_array('domain.feature:'.$feature, $route->gatherMiddleware(), true)) {
                $names[] = preg_replace('/^domains\./', '', $route->getName());
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function role(string $name): Role
    {
        return Role::findOrCreate($name, 'web');
    }

    private function routesOf(Role $role): array
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->sorted($role->fresh()->permissions->pluck('route_name')->all());
    }

    public function test_the_seeders_cover_every_route_behind_their_feature(): void
    {
        // Expenses has its own feature gate (it can come without the rest of Finance), but the
        // Finance seeder sets up its permissions too.
        $this->assertSame(
            $this->sorted([...$this->routesBehind('finance'), ...$this->routesBehind('expenses')]),
            $this->sorted(FinancePermissionSeeder::ROUTES),
        );
        $this->assertSame($this->routesBehind('ai'), $this->sorted(AssistantPermissionSeeder::ROUTES));
    }

    public function test_the_purchasing_seeder_covers_every_purchasing_route(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'domains.purchase-orders.')) {
                $routes[] = preg_replace('/^domains\./', '', $route->getName());
            }
        }

        $this->assertSame($this->sorted($routes), $this->sorted(PurchasingPermissionSeeder::ROUTES));
    }

    public function test_the_sales_history_and_modifier_seeders_cover_their_routes(): void
    {
        $named = function (string $prefix): array {
            $names = [];
            foreach (Route::getRoutes() as $route) {
                if (str_starts_with((string) $route->getName(), 'domains.'.$prefix)) {
                    $names[] = preg_replace('/^domains\./', '', $route->getName());
                }
            }

            return $this->sorted(array_unique($names));
        };

        $this->assertSame($named('sales-history.'), $this->sorted(SalesHistoryPermissionSeeder::ROUTES));
        $this->assertSame($named('products.modifier-groups.'), $this->sorted(ProductModifierPermissionSeeder::ROUTES));
    }

    public function test_sales_history_and_modifier_permissions_go_to_the_right_default_roles(): void
    {
        $admin = $this->role('admin');
        $supervisor = $this->role('supervisor');
        $cashier = $this->role('cashier');

        $this->seed(SalesHistoryPermissionSeeder::class);
        $this->seed(ProductModifierPermissionSeeder::class);
        $this->seed(SalesHistoryPermissionSeeder::class);

        $this->assertSame($this->sorted(SalesHistoryPermissionSeeder::ROUTES), array_values(array_intersect($this->sorted(SalesHistoryPermissionSeeder::ROUTES), $this->routesOf($admin))));
        // Cashiers and supervisors list, open and void, but cannot export.
        foreach ([$supervisor, $cashier] as $role) {
            $held = $this->routesOf($role);
            $this->assertContains('sales-history.index', $held);
            $this->assertNotContains('sales-history.export', $held);
        }
        // Cashiers can view modifiers, not change them; supervisors can change them.
        $this->assertContains('products.modifier-groups.index', $this->routesOf($cashier));
        $this->assertNotContains('products.modifier-groups.store', $this->routesOf($cashier));
        $this->assertContains('products.modifier-groups.store', $this->routesOf($supervisor));
        $this->assertSame(
            count(SalesHistoryPermissionSeeder::ROUTES) + count(ProductModifierPermissionSeeder::ROUTES),
            Permission::query()->whereIn('route_name', [...SalesHistoryPermissionSeeder::ROUTES, ...ProductModifierPermissionSeeder::ROUTES])->count(),
        );
    }

    public function test_everyone_on_the_till_can_reopen_a_shift(): void
    {
        $cashier = $this->role('cashier');
        $supervisor = $this->role('supervisor');
        $manager = $this->role('manager');

        $this->seed(ShiftReopenPermissionSeeder::class);
        $this->seed(ShiftReopenPermissionSeeder::class);

        foreach ([$cashier, $supervisor, $manager] as $role) {
            $this->assertContains('wallet-cash-ledger.reopen-shift', $this->routesOf($role));
        }
        $this->assertSame(1, Permission::query()->where('route_name', 'wallet-cash-ledger.reopen-shift')->count());
    }

    public function test_the_register_shift_seeder_covers_every_shift_route(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'domains.register-shifts.')) {
                $routes[] = preg_replace('/^domains\./', '', $route->getName());
            }
        }

        // register-shifts.show opens with register-shifts.index (RoutePermission::ALIASES).
        $this->assertSame(
            $this->sorted(array_values(array_diff($routes, ['register-shifts.show']))),
            $this->sorted([...RegisterShiftPermissionSeeder::TILL_ROUTES, ...RegisterShiftPermissionSeeder::REVIEW_ROUTES]),
        );
    }

    public function test_cashiers_run_their_own_shift_and_supervisors_and_up_approve_counts(): void
    {
        $cashier = $this->role('cashier');
        $supervisor = $this->role('supervisor');
        $manager = $this->role('manager');

        $this->seed(RegisterShiftPermissionSeeder::class);
        $this->seed(RegisterShiftPermissionSeeder::class);

        $all = $this->sorted([...RegisterShiftPermissionSeeder::TILL_ROUTES, ...RegisterShiftPermissionSeeder::REVIEW_ROUTES]);
        $this->assertSame($this->sorted(RegisterShiftPermissionSeeder::TILL_ROUTES), $this->routesOf($cashier));
        // Supervisors also get Today's drawer, where a shift is run (they didn't have it before).
        $this->assertSame($this->sorted([...$all, ...RegisterShiftPermissionSeeder::DRAWER_ROUTES]), $this->routesOf($supervisor));
        $this->assertSame($all, $this->routesOf($manager));
        $this->assertSame(count($all), Permission::query()->whereIn('route_name', $all)->count());
        $this->assertSame(
            DB::table('permission_modules')->where('name', 'register-shifts')->value('id'),
            Permission::query()->where('route_name', 'register-shifts.approve')->value('module_id'),
        );
    }

    public function test_the_sales_import_seeder_covers_every_sales_import_route(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'domains.sales-import.')) {
                $routes[] = preg_replace('/^domains\./', '', $route->getName());
            }
        }

        $this->assertSame($this->sorted($routes), $this->sorted(SalesImportPermissionSeeder::ROUTES));
    }

    public function test_sales_import_permissions_are_added_for_the_default_roles_only(): void
    {
        $admin = $this->role('admin');
        $manager = $this->role('manager');
        $cashier = $this->role('cashier');

        $this->seed(SalesImportPermissionSeeder::class);
        $this->seed(SalesImportPermissionSeeder::class);

        $routes = $this->sorted(SalesImportPermissionSeeder::ROUTES);
        $this->assertSame($routes, $this->routesOf($admin));
        $this->assertSame($routes, $this->routesOf($manager));
        $this->assertSame([], $this->routesOf($cashier));
        $this->assertSame(count($routes), Permission::query()->whereIn('route_name', $routes)->count());
    }

    public function test_the_cash_on_hand_seeder_covers_every_cash_on_hand_route(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'domains.cash-on-hand.')) {
                $routes[] = preg_replace('/^domains\./', '', $route->getName());
            }
        }

        $this->assertSame($this->sorted($routes), $this->sorted(CashOnHandPermissionSeeder::ROUTES));
    }

    public function test_cash_on_hand_is_for_owners_and_admins_only(): void
    {
        $admin = $this->role('admin');
        $manager = $this->role('manager');
        $cashier = $this->role('cashier');

        $this->seed(CashOnHandPermissionSeeder::class);
        $this->seed(CashOnHandPermissionSeeder::class);

        $routes = $this->sorted(CashOnHandPermissionSeeder::ROUTES);
        $this->assertSame($routes, $this->routesOf($admin));
        // It moves money out of the stores' drawers: not for managers or cashiers.
        $this->assertSame([], $this->routesOf($manager));
        $this->assertSame([], $this->routesOf($cashier));
        $this->assertSame(count($routes), Permission::query()->whereIn('route_name', $routes)->count());
        $this->assertSame(
            DB::table('permission_modules')->where('name', 'cash-on-hand')->value('id'),
            Permission::query()->where('route_name', 'cash-on-hand.collect')->value('module_id'),
        );
    }

    public function test_purchasing_permissions_are_added_for_the_default_roles_only(): void
    {
        $admin = $this->role('admin');
        $manager = $this->role('manager');
        $cashier = $this->role('cashier');

        $this->seed(PurchasingPermissionSeeder::class);
        $this->seed(PurchasingPermissionSeeder::class);

        $purchasing = $this->sorted(PurchasingPermissionSeeder::ROUTES);
        foreach ([$admin, $manager] as $role) {
            $this->assertSame([], array_values(array_diff($purchasing, $this->routesOf($role))));
        }
        $this->assertSame([], array_values(array_intersect($purchasing, $this->routesOf($cashier))));
        $this->assertSame(count($purchasing), Permission::query()->whereIn('route_name', $purchasing)->count());
    }

    public function test_finance_permissions_are_added_once_for_the_default_roles(): void
    {
        $admin = $this->role('admin');
        $manager = $this->role('manager');
        $cashier = $this->role('cashier');

        $this->seed(FinancePermissionSeeder::class);

        $finance = $this->sorted(FinancePermissionSeeder::ROUTES);
        $this->assertSame($finance, $this->routesOf($admin));
        $this->assertSame($finance, $this->routesOf($manager));
        $this->assertSame([], $this->routesOf($cashier));

        // Named like RolePermissionSeeder names them, under their module headers.
        $dashboard = Permission::query()->where('route_name', 'finance.dashboard')->sole();
        $this->assertSame(DB::table('permission_modules')->where('name', 'finance')->value('id'), $dashboard->module_id);
        $this->assertSame('dashboard', $dashboard->action);
        $this->assertSame(
            DB::table('permission_modules')->where('name', 'vat-report')->value('id'),
            Permission::query()->where('route_name', 'vat-report.index')->value('module_id'),
        );

        // Again: nothing new.
        $count = Permission::query()->count();
        $this->seed(FinancePermissionSeeder::class);
        $this->assertSame($count, Permission::query()->count());
    }

    public function test_it_only_adds_and_keeps_existing_permissions_as_they_are(): void
    {
        $manager = $this->role('manager');
        $cashier = $this->role('cashier');
        $other = Permission::query()->firstOrCreate(['route_name' => 'sales.index', 'guard_name' => 'web'], ['name' => 'View (sales.index)', 'action' => 'index']);
        $manager->givePermissionTo($other);
        $cashier->givePermissionTo($other);

        // A permission the migrations already made keeps its name, and is not made twice.
        $existing = Permission::query()->where('route_name', 'finance.dashboard')->first()
            ?? Permission::query()->create(['name' => 'View finance dashboard', 'guard_name' => 'web', 'route_name' => 'finance.dashboard', 'action' => 'dashboard']);
        $existing->update(['name' => 'Custom name']);
        // One missing from the database is created (only RolePermissionSeeder makes this one).
        Permission::query()->where('route_name', 'vat-report.export-json')->delete();

        $this->seed(FinancePermissionSeeder::class);

        $this->assertSame('Custom name', $existing->fresh()->name);
        $this->assertSame(1, Permission::query()->where('route_name', 'finance.dashboard')->count());
        $this->assertSame('Export JSON', Permission::query()->where('route_name', 'vat-report.export-json')->value('name'));

        $routes = $this->routesOf($manager);
        $this->assertContains('sales.index', $routes, 'other permissions are kept');
        $this->assertContains('finance.dashboard', $routes, 'existing permissions are given to the default roles');
        $this->assertContains('vat-report.export-json', $routes, 'new ones too');
        $this->assertSame(['sales.index'], $this->routesOf($cashier), 'roles outside the defaults are untouched');
    }

    public function test_assistant_permissions_go_to_every_role(): void
    {
        $roles = array_map(fn (string $name) => $this->role($name), AssistantPermissionSeeder::ROLES);

        $this->seed(AssistantPermissionSeeder::class);

        foreach ($roles as $role) {
            // The AI usage page only for the roles that see billing.
            $expected = in_array($role->name, AssistantPermissionSeeder::USAGE_ROLES, true)
                ? AssistantPermissionSeeder::ROUTES
                : array_diff(AssistantPermissionSeeder::ROUTES, AssistantPermissionSeeder::USAGE_ROUTES);
            $this->assertSame($this->sorted(array_values($expected)), $this->routesOf($role), $role->name);
        }
        $this->assertSame(
            DB::table('permission_modules')->where('name', 'assistant')->value('id'),
            Permission::query()->where('route_name', 'assistant.ask')->value('module_id'),
        );
    }

    public function test_the_timeclock_seeder_covers_every_timesheet_route_and_gives_the_till_staff_only_the_punch(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'domains.timesheets.')) {
                $routes[] = preg_replace('/^domains\./', '', $route->getName());
            }
        }
        $this->assertSame($this->sorted($routes), $this->sorted(TimeclockPermissionSeeder::ROUTES));

        $manager = $this->role('manager');
        $cashier = $this->role('cashier');
        $supervisor = $this->role('supervisor');

        $this->seed(TimeclockPermissionSeeder::class);
        $this->seed(TimeclockPermissionSeeder::class);

        $this->assertSame($this->sorted(TimeclockPermissionSeeder::ROUTES), $this->routesOf($manager));
        $this->assertSame(['timesheets.punch'], $this->routesOf($cashier));
        $this->assertSame(['timesheets.punch'], $this->routesOf($supervisor));
        $this->assertSame(count(TimeclockPermissionSeeder::ROUTES), Permission::query()->whereIn('route_name', TimeclockPermissionSeeder::ROUTES)->count());
    }

    public function test_the_dine_in_seeder_covers_every_route_and_gives_the_till_staff_only_the_tabs(): void
    {
        $this->assertSame($this->routesBehind('dine_in'), $this->sorted([...DineInPermissionSeeder::TAB_ROUTES, ...DineInPermissionSeeder::TABLE_ROUTES]));

        $manager = $this->role('manager');
        $cashier = $this->role('cashier');

        $this->seed(DineInPermissionSeeder::class);
        $this->seed(DineInPermissionSeeder::class);

        $this->assertSame($this->sorted([...DineInPermissionSeeder::TAB_ROUTES, ...DineInPermissionSeeder::TABLE_ROUTES]), $this->routesOf($manager));
        $this->assertSame($this->sorted(DineInPermissionSeeder::TAB_ROUTES), $this->routesOf($cashier));
        $this->assertSame(
            DB::table('permission_modules')->where('name', 'dining-tables')->value('id'),
            Permission::query()->where('route_name', 'dining-tables.layout')->value('module_id'),
        );
    }
}
