<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\LoyaltyTier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the Playwright loyalty suite (tests/e2e/loyalty). Runs after E2ESalesSeeder, whose
 * customers the sales suite spends and redeems against.
 *
 * Four members with fixed points, tiers and lifetime spending for the customer list, its search and
 * its tier filter, plus one member per Playwright worker for the points adjustment tests, since
 * those change the member they act on. Rebuilt every run. Local/testing only.
 *
 * Tiers are left alone: each organization is seeded with its own set (see TierSeeder), and the tier
 * tests create their own alongside those and drop them again.
 */
class E2ELoyaltySeeder extends Seeder
{
    public const DOMAIN = 'jollibee-corp';

    /** One adjustable member per Playwright worker (playwright.config.js workers). */
    public const WORKER_MEMBERS = 4;

    private const ADJUSTABLE_POINTS = 100;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2ELoyaltySeeder may only run in local or testing environments.');
        }

        $fixtures = DB::transaction(function () {
            Customer::where('email', 'like', 'e2e-loy-%@techiko.test')->delete();

            // name, tier, points, lifetime spent
            $bronze = $this->member('E2E Loy Bronze', 'bronze', 150, 1234.50);
            $silver = $this->member('E2E Loy Silver', 'silver', 2500, 25000);
            $gold = $this->member('E2E Loy Gold', 'gold', 7500, 55000);
            $noPoints = $this->member('E2E Loy Sleeper', 'bronze', 0, 0);

            // Registered two years ago, so the customers page's registration-period filter has
            // somebody to leave out.
            $longStanding = $this->member('E2E Loy Veteran', 'silver', 900, 21000);
            $longStanding->forceFill(['created_at' => now()->subYears(2)])->save();

            $adjustable = [];
            for ($n = 1; $n <= self::WORKER_MEMBERS; $n++) {
                $member = $this->member("E2E Loy Adjust {$n}", 'bronze', self::ADJUSTABLE_POINTS, 500);
                $adjustable[] = ['id' => $member->id, 'name' => $member->name, 'email' => $member->email];
            }

            return [
                'members' => [
                    'bronze' => $this->row($bronze),
                    'silver' => $this->row($silver),
                    'gold' => $this->row($gold),
                    'noPoints' => $this->row($noPoints),
                    'longStanding' => $this->row($longStanding->refresh()),
                ],
                'adjustable' => $adjustable,
                'adjustablePoints' => self::ADJUSTABLE_POINTS,
                'otherOrgCustomerId' => Customer::where('domain', 'mcdonalds-corp')->value('id'),
            ];
        });

        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all['loyalty'] = $fixtures;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function member(string $name, string $tier, int $points, float $spent): Customer
    {
        $slug = strtolower(str_replace(' ', '-', substr($name, 4)));

        return Customer::create([
            'domain' => self::DOMAIN,
            'name' => $name,
            'email' => "e2e-{$slug}@techiko.test",
            'phone' => '0917'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'loyalty_points' => $points,
            'tier' => $tier,
            'lifetime_spent' => $spent,
            'total_purchases' => $points > 0 ? 3 : 0,
            'tier_achieved_date' => '2026-01-15',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'tier' => $customer->tier,
            'points' => $customer->loyalty_points,
            'spent' => (float) $customer->lifetime_spent,
        ];
    }
}
