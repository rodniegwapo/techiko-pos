<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsFeaturePermissions;
use Illuminate\Database\Seeder;

/**
 * The AI assistant's permissions (asking it, and confirming or cancelling the changes it prepares),
 * for an existing database: php artisan db:seed --class=AssistantPermissionSeeder --force
 *
 * Creates any missing permission and gives every role the ones it lacks, like RolePermissionSeeder (what the
 * assistant can do for each person still follows their other permissions). It never removes anything.
 */
class AssistantPermissionSeeder extends Seeder
{
    use SeedsFeaturePermissions;

    public const ROUTES = [
        'assistant.ask',
        'assistant.actions.confirm',
        'assistant.actions.cancel',
    ];

    public const ROLES = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];

    public function run(): void
    {
        $this->seedFeaturePermissions('AI assistant', ['assistant'], self::ROUTES, self::ROLES);
    }
}
