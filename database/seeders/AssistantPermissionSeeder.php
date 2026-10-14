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

    /** Every route behind domain.feature:ai. */
    public const ROUTES = [
        'assistant.ask',
        'assistant.actions.confirm',
        'assistant.actions.cancel',
        ...self::USAGE_ROUTES,
    ];

    public const ROLES = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];

    /** The AI usage page goes with the plan and billing, so only the roles that see billing get it. */
    public const USAGE_ROUTES = ['ai-usage.index'];

    public const USAGE_ROLES = ['super admin', 'admin', 'manager'];

    public function run(): void
    {
        $this->seedFeaturePermissions('AI assistant', ['assistant'], array_values(array_diff(self::ROUTES, self::USAGE_ROUTES)), self::ROLES);
        $this->seedFeaturePermissions('AI usage', ['assistant'], self::USAGE_ROUTES, self::USAGE_ROLES);
    }
}
