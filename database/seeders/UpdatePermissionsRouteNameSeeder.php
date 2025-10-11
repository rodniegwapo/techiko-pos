<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class UpdatePermissionsRouteNameSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all permissions that don't have route_name set
        $permissions = Permission::whereNull('route_name')->get();

        foreach ($permissions as $permission) {
            // Set route_name to the current name (which contains the technical route)
            $permission->update([
                'route_name' => $permission->name
            ]);
        }

        $this->command->info('Updated ' . $permissions->count() . ' permissions with route_name.');
    }
}