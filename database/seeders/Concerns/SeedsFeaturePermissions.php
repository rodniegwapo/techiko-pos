<?php

namespace Database\Seeders\Concerns;

use App\Models\Permission;
use Database\Seeders\PermissionModuleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sets up one feature's permissions on an existing database (e.g. production) without touching
 * anything else: it creates the permissions that are missing (named like RolePermissionSeeder
 * names them; the migrations already create most of them), then gives the feature's default roles
 * any of them they don't have yet. It only adds: existing permissions keep their names, and roles
 * keep all their other permissions. Running it again changes nothing, except that a default role
 * gets back a permission of this feature that was taken away on the Roles page.
 */
trait SeedsFeaturePermissions
{
    /**
     * @param  list<string>  $modules  permission_modules names (from PermissionModuleSeeder::modules()).
     * @param  list<string>  $routes  Route permissions, e.g. "finance.dashboard".
     * @param  list<string>  $roles  Roles that get the new permissions; a role that doesn't exist is skipped.
     */
    protected function seedFeaturePermissions(string $feature, array $modules, array $routes, array $roles): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (PermissionModuleSeeder::modules() as $module) {
            if (in_array($module['name'], $modules, true)) {
                DB::table('permission_modules')->updateOrInsert(['name' => $module['name']], $module);
            }
        }
        $moduleIds = DB::table('permission_modules')->whereIn('name', $modules)->pluck('id', 'name');

        $labels = new RolePermissionSeeder;
        $created = 0;
        foreach ($routes as $route) {
            if (Permission::query()->where('route_name', $route)->where('guard_name', 'web')->exists()) {
                continue;
            }
            $parts = explode('.', $route);
            $action = end($parts);
            $name = $labels->getActionDisplayName($action);
            if (Permission::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
                $name .= ' ('.$route.')';
            }

            Permission::query()->create([
                'name' => $name,
                'guard_name' => 'web',
                'route_name' => $route,
                'action' => $action,
                'module_id' => $moduleIds[$parts[0]] ?? null,
            ]);
            $created++;
        }

        $permissions = Permission::query()->whereIn('route_name', $routes)->where('guard_name', 'web')->get();
        $given = [];
        foreach ($roles as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                $given[] = $roleName.' (no such role, skipped)';

                continue;
            }
            $missing = $permissions->whereNotIn('id', $role->permissions()->pluck('id'))->values();
            if ($missing->isNotEmpty()) {
                $role->givePermissionTo($missing);
            }
            $given[] = $roleName.' +'.$missing->count();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command?->info($feature.': '.$created.' new permission(s) of '.count($routes).'; roles: '.implode(', ', $given).'.');
    }
}
