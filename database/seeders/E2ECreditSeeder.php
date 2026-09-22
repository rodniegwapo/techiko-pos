<?php

namespace Database\Seeders;

use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the Playwright credits suite (tests/e2e/credits).
 *
 * The credits page sorts customers into states — overdue, at their limit, in good standing, credit
 * switched off — so each of those needs somebody in it for the status filter to have something to
 * include and something to leave out:
 *
 *  - overdue:       owes 400 of a 1000 limit, on an invoice that fell due ten days ago
 *  - at limit:      owes the whole 300 they are allowed
 *  - good standing: owes 250 of 1000, on an invoice not yet due
 *  - disabled:      no credit at all
 *
 * Plus one payable account per Playwright worker, because recording a payment changes the balance
 * it is recorded against, and one account in another organization so the page can be held to
 * showing only its own.
 *
 * Runs after E2ESalesSeeder, which resets the credit of the customers the sales suite buys with.
 * Rebuilt every run; local/testing only.
 */
class E2ECreditSeeder extends Seeder
{
    public const DOMAIN = 'jollibee-corp';
    public const OTHER_DOMAIN = 'mcdonalds-corp';

    /** One payable account per Playwright worker (playwright.config.js workers). */
    public const WORKER_ACCOUNTS = 4;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2ECreditSeeder may only run in local or testing environments.');
        }

        $fixtures = DB::transaction(function () {
            $this->wipe();

            $overdue = $this->account('E2E Cred Overdue', self::DOMAIN, limit: 1000, owed: 400, dueInDays: -10);
            $atLimit = $this->account('E2E Cred At Limit', self::DOMAIN, limit: 300, owed: 300, dueInDays: 20);
            $goodStanding = $this->account('E2E Cred Good', self::DOMAIN, limit: 1000, owed: 250, dueInDays: 20);
            $disabled = $this->account('E2E Cred Disabled', self::DOMAIN, limit: 0, owed: 0, dueInDays: null, enabled: false);

            $payable = [];
            for ($n = 1; $n <= self::WORKER_ACCOUNTS; $n++) {
                $payable[] = $this->account("E2E Cred Payable {$n}", self::DOMAIN, limit: 1000, owed: 500, dueInDays: 15);
            }

            $other = $this->account('E2E Cred Elsewhere', self::OTHER_DOMAIN, limit: 800, owed: 600, dueInDays: -5);

            return compact('overdue', 'atLimit', 'goodStanding', 'disabled', 'payable', 'other');
        });

        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all['credit'] = $fixtures;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** The organization's first cashier, recorded as whoever rang the sale up. */
    private function cashier(string $domain): int
    {
        $id = User::where('domain', $domain)
            ->whereHas('roles', fn ($q) => $q->where('name', 'cashier'))
            ->orderBy('id')
            ->value('id');

        if (! $id) {
            throw new RuntimeException("No cashier seeded for {$domain}; run DatabaseSeeder first.");
        }

        return $id;
    }

    private function wipe(): void
    {
        $ids = Customer::where('email', 'like', 'e2e-cred-%@techiko.test')->pluck('id');
        CreditTransaction::whereIn('customer_id', $ids)->delete();
        Customer::whereIn('id', $ids)->delete();
    }

    /**
     * A customer owing `owed` against `limit`, on a single unpaid invoice falling due `dueInDays`
     * from today — a negative number puts it in the past, which is what makes them overdue.
     */
    private function account(
        string $name,
        string $domain,
        float $limit,
        float $owed,
        ?int $dueInDays,
        bool $enabled = true,
    ): array {
        $slug = strtolower(str_replace(' ', '-', substr($name, 4)));

        $customer = Customer::create([
            'domain' => $domain,
            'name' => $name,
            'email' => "e2e-{$slug}@techiko.test",
            'credit_enabled' => $enabled,
            'credit_limit' => $limit,
            'credit_balance' => $owed,
            'credit_terms_days' => 30,
            'tier' => 'bronze',
            'loyalty_points' => 0,
            'lifetime_spent' => 0,
        ]);

        if ($owed > 0 && $dueInDays !== null) {
            CreditTransaction::create([
                'customer_id' => $customer->id,
                'domain' => $domain,
                'transaction_type' => 'credit',
                'amount' => $owed,
                'balance_before' => 0,
                'balance_after' => $owed,
                'due_date' => now()->addDays($dueInDays)->toDateString(),
                'notes' => 'E2E seeded invoice',
                // The column takes no default; the sale would have been rung up by a cashier.
                'user_id' => $this->cashier($domain),
            ]);
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'domain' => $domain,
            'limit' => $limit,
            'owed' => $owed,
            'available' => max(0, $limit - $owed),
            'enabled' => $enabled,
        ];
    }
}
