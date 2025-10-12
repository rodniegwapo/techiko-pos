<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Permission;

echo "Testing permission grouping...\n\n";

// Load all permissions with their modules
$all = Permission::with('module')->get();

// Group by module using the module relationship
$grouped = collect($all)
    ->groupBy(function (Permission $permission) {
        return $permission->module ? $permission->module->display_name : 'Other';
    });

echo "Grouped permissions structure:\n";
foreach($grouped as $moduleName => $permissions) {
    echo "Module: {$moduleName}\n";
    echo "  Permissions count: " . $permissions->count() . "\n";
    echo "  First permission: " . $permissions->first()->name . "\n";
    echo "\n";
}

echo "Total modules: " . $grouped->count() . "\n";
echo "Module names: " . implode(', ', $grouped->keys()->toArray()) . "\n";

