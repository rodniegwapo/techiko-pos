<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\Domain;
use App\Services\DomainSubscriptionService;

/**
 * The Free plan's limits (organizations without a service tier), set by super admins on the Plans
 * page. Until they are changed, each falls back to its old fixed value. A null limit is unlimited.
 * The user and history limits only apply while plans are enforced (config features.plan_gating);
 * the product limit only while config features.unlimited_products is off.
 */
class FreePlan
{
    public const SETTING = 'free_plan';

    /** @return array{ai_monthly_credits: int, max_users: ?int, max_products: ?int, history_days: ?int} */
    public static function defaults(): array
    {
        return [
            'ai_monthly_credits' => (int) config('ai_billing.free_monthly_credits', 30),
            'max_users' => DomainSubscriptionService::FREE_TIER_MAX_USERS,
            'max_products' => DomainSubscriptionService::FREE_TIER_MAX_PRODUCTS,
            'history_days' => Domain::FREE_SALES_HISTORY_DAYS,
        ];
    }

    /** @return array{ai_monthly_credits: int, max_users: ?int, max_products: ?int, history_days: ?int} */
    public static function all(): array
    {
        $saved = AppSetting::get(self::SETTING, []);

        return array_replace(self::defaults(), array_intersect_key(is_array($saved) ? $saved : [], self::defaults()));
    }

    /** @param  array{ai_monthly_credits?: int, max_users?: ?int, max_products?: ?int, history_days?: ?int}  $limits */
    public static function update(array $limits): void
    {
        AppSetting::put(self::SETTING, array_replace(self::all(), array_intersect_key($limits, self::defaults())));
    }

    public static function aiCredits(): int
    {
        return (int) self::all()['ai_monthly_credits'];
    }

    public static function maxUsers(): ?int
    {
        return self::all()['max_users'];
    }

    public static function maxProducts(): ?int
    {
        return self::all()['max_products'];
    }

    public static function historyDays(): ?int
    {
        return self::all()['history_days'];
    }
}
