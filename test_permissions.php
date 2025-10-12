<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Permission;

echo "Testing permissions and modules...\n\n";

$permissions = Permission::with('module')->take(5)->get();

foreach($permissions as $p) {
    echo "Permission: {$p->name}\n";
    echo "  - Module ID: " . ($p->module_id ?? 'NULL') . "\n";
    echo "  - Module Name: " . ($p->module ? $p->module->name : 'NULL') . "\n";
    echo "  - Module Display Name: " . ($p->module ? $p->module->display_name : 'NULL') . "\n";
    echo "  - Route Name: " . ($p->route_name ?? 'NULL') . "\n";
    echo "  - Action: " . ($p->action ?? 'NULL') . "\n";
    echo "\n";
}

echo "Total permissions: " . Permission::count() . "\n";
echo "Permissions with module_id: " . Permission::whereNotNull('module_id')->count() . "\n";
echo "Permissions without module_id: " . Permission::whereNull('module_id')->count() . "\n";

