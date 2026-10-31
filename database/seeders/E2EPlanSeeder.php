<?php

namespace Database\Seeders;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\ServiceTier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * One organization per plan for tests/e2e/plans: e2e-plan-free, -basic, -standard and -premium,
 * each with one store, an admin and two paid sales (5 and 60 days ago). The free one also has a
 * cashier, so it is at its 2-user limit. Re-running puts each back: users and locations a test
 * added are removed. The plans themselves are left as super admins set them.
 */
class E2EPlanSeeder extends Seeder
{
    /** Plan slug => organization slug (free has no plan). */
    public const ORGS = [
        'free' => 'e2e-plan-free',
        'basic' => 'e2e-plan-basic',
        'standard' => 'e2e-plan-standard',
        'premium' => 'e2e-plan-premium',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EPlanSeeder may only run in local or testing environments.');
        }

        foreach (self::ORGS as $plan => $slug) {
            $tier = $plan === 'free' ? null : ServiceTier::query()->where('slug', $plan)->first();
            if ($plan !== 'free' && ! $tier) {
                throw new RuntimeException("The {$plan} plan is missing; run ServiceTierSeeder first.");
            }

            DB::transaction(fn () => $this->seedOrg($plan, $slug, $tier));
        }
    }

    private function seedOrg(string $plan, string $slug, ?ServiceTier $tier): void
    {
        $domain = Domain::query()->updateOrCreate(['name_slug' => $slug], [
            'name' => 'E2E Plan '.ucfirst($plan),
            'current_service_tier_id' => $tier?->id,
            'subscription_started_at' => $tier ? now() : null,
            'subscription_ends_at' => $tier ? now()->addYear() : null,
            'locked_at' => null,
            'is_active' => true,
        ]);
        // Only what the plan gives: nothing turned on by a super admin.
        $settings = $domain->settings ?? [];
        unset($settings['features']);
        // No business type picked, so their admins see the "what do you sell?" banner (business-types spec).
        unset($settings['sales']['business_type']);
        $domain->update(['settings' => $settings]);

        $code = 'E2EP-'.strtoupper(substr($plan, 0, 4)); // codes hold 10 characters
        $store = InventoryLocation::query()->updateOrCreate(['code' => $code], [
            'domain' => $slug,
            'name' => 'E2E Plan '.ucfirst($plan).' Store',
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);
        $users = [['admin', 2]];
        if ($plan === 'free') {
            $users[] = ['cashier', 5];
        }
        $keep = [];
        foreach ($users as [$role, $level]) {
            $user = User::query()->updateOrCreate(['email' => "{$slug}-{$role}@techiko.test"], [
                'name' => 'E2E Plan '.ucfirst($plan).' '.ucfirst($role),
                'password' => Hash::make(E2EUserSeeder::PASSWORD),
                'is_super_user' => false,
                'domain' => $slug,
                'role_level' => $level,
                'location_id' => $store->id,
                'can_switch_locations' => $level <= 2,
                'email_verified_at' => now(),
            ]);
            $user->syncRoles([$role]);
            $keep[] = $user->id;
        }
        // Users a test added.
        User::query()->where('domain', $slug)->whereNotIn('id', $keep)->get()->each->delete();

        DB::table('sales')->where('domain', $slug)->delete();
        // Locations a test added (none hold stock), once nothing points at them any more.
        InventoryLocation::query()->where('domain', $slug)->whereKeyNot($store->id)->delete();

        $now = now();
        foreach ([5, 60] as $daysAgo) {
            DB::table('sales')->insert([
                'domain' => $slug,
                'invoice_number' => sprintf('E2EP-%s-%dD', strtoupper($plan), $daysAgo),
                'user_id' => $keep[0],
                'location_id' => $store->id,
                'total_amount' => 100,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'grand_total' => 100,
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'is_credit_sale' => false,
                'transaction_date' => $now->copy()->subDays($daysAgo)->setTime(10, 30),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
