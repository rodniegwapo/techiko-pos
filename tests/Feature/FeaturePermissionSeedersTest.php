<?php

namespace Tests\Feature;

use App\Models\Permission;
use Database\Seeders\AssistantPermissionSeeder;
use Database\Seeders\FinancePermissionSeeder;
use Database\Seeders\PurchasingPermissionSeeder;
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
}
