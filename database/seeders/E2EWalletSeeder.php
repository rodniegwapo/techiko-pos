<?php

namespace Database\Seeders;

use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashOpeningAudit;
use App\Models\WalletCashReconciliation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Fixtures for the Playwright wallet suite (tests/e2e/wallet).
 *
 * Wallet data (opening cash, counts, closed shifts, ledger) is shared by everyone at a store
 * for a business date, so tests never touch a real store. Each Playwright worker gets its own
 * store and two managers there (a second one to check "only the closer can reopen"). The
 * stores are inactive so they don't appear in location pickers; the wallet looks them up by ID.
 *
 * Wallet rows for these stores are wiped every run. Runs only in local/testing.
 */
class E2EWalletSeeder extends Seeder
{
    public const DOMAIN = 'jollibee-corp';

    public const WORKERS = 4;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EWalletSeeder may only run in local or testing environments.');
        }

        $workers = DB::transaction(function () {
            $workers = [];

            for ($i = 1; $i <= self::WORKERS; $i++) {
                $location = InventoryLocation::updateOrCreate(
                    ['code' => "E2E-WAL-{$i}"],
                    [
                        'domain' => self::DOMAIN,
                        'name' => "E2E Wallet Store {$i}",
                        'type' => 'store',
                        'is_active' => false,
                        'is_default' => false,
                        'notes' => 'Playwright wallet tests only',
                    ]
                );

                $this->wipeWallet($location);

                $manager = $this->manager("e2e-wallet-manager-{$i}@techiko.test", "E2E Wallet Manager {$i}", $location);
                $partner = $this->manager("e2e-wallet-partner-{$i}@techiko.test", "E2E Wallet Partner {$i}", $location);

                $card = PaymentCardType::updateOrCreate(
                    ['domain' => self::DOMAIN, 'location_id' => $location->id, 'name' => 'E2E Wallet Visa'],
                    ['is_active' => true, 'sort_order' => 0]
                );
                $otherCard = PaymentCardType::updateOrCreate(
                    ['domain' => self::DOMAIN, 'location_id' => $location->id, 'name' => 'E2E Wallet Mastercard'],
                    ['is_active' => true, 'sort_order' => 1]
                );

                // Card types created by card-terminals tests (the seeded two stay).
                PaymentCardType::where('location_id', $location->id)
                    ->whereNotIn('id', [$card->id, $otherCard->id])
                    ->delete();

                $cardSales = $this->seedSales($i, $location, $manager, $card, $otherCard);

                $workers[$i] = [
                    'locationId' => $location->id,
                    'locationName' => $location->name,
                    'managerEmail' => $manager->email,
                    'managerName' => $manager->name,
                    'partnerEmail' => $partner->email,
                    'partnerName' => $partner->name,
                    'cardTypeId' => $card->id,
                    'otherCardTypeId' => $otherCard->id,
                    'cardSales' => $cardSales,
                ];
            }

            return $workers;
        });

        $this->writeFixtureIds($workers);
    }

    private function manager(string $email, string $name, InventoryLocation $location): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(E2EUserSeeder::PASSWORD),
                'is_super_user' => false,
                'domain' => self::DOMAIN,
                'role_level' => 3,
                'location_id' => $location->id,
                'can_switch_locations' => false,
                'email_verified_at' => now(),
            ]
        );
        $user->syncRoles(['manager']);

        return $user;
    }

    /**
     * Paid sales for the card terminal pages, inserted directly (no sale events): card sales on
     * the Visa terminal (three dated ones plus 21 older ones for pagination), and sales that must
     * NOT show in its history: one on the Mastercard terminal and a pending card sale. Credit sales
     * feed the Credit totals on the Card terminals page. No cash sales, so cash control is untouched.
     *
     * @return array{recent: list<array{invoice: string, amount: float, daysAgo: int}>, total: int, todayTotal: float, yesterdayTotal: float, creditToday: float, creditYesterday: float, otherCardInvoice: string, pendingInvoice: string}
     */
    private function seedSales(int $worker, InventoryLocation $location, User $cashier, PaymentCardType $card, PaymentCardType $otherCard): array
    {
        $now = now();
        $rows = [];
        $sale = function (string $invoice, string $method, ?int $cardId, string $status, float $amount, int $daysAgo) use (&$rows, $location, $cashier, $now) {
            $rows[] = [
                'domain' => self::DOMAIN,
                'invoice_number' => $invoice,
                'user_id' => $cashier->id,
                'location_id' => $location->id,
                'total_amount' => $amount,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'grand_total' => $amount,
                'payment_method' => $method,
                'payment_card_type_id' => $cardId,
                'payment_status' => $status,
                'is_credit_sale' => $method === 'credit',
                'transaction_date' => $now->copy()->subDays($daysAgo)->setTime(10, 30),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        $recent = [
            ['invoice' => "E2E-W{$worker}-CARD-TODAY", 'amount' => 150.0, 'daysAgo' => 0],
            ['invoice' => "E2E-W{$worker}-CARD-YESTERDAY", 'amount' => 200.0, 'daysAgo' => 1],
            ['invoice' => "E2E-W{$worker}-CARD-OLDER", 'amount' => 300.0, 'daysAgo' => 10],
        ];
        foreach ($recent as $r) {
            $sale($r['invoice'], 'card', $card->id, 'paid', $r['amount'], $r['daysAgo']);
        }
        for ($p = 1; $p <= 21; $p++) {
            $sale(sprintf('E2E-W%d-CARD-PAGE-%02d', $worker, $p), 'card', $card->id, 'paid', 10.0, 30 + $p);
        }

        $sale("E2E-W{$worker}-OTHER-CARD", 'card', $otherCard->id, 'paid', 999.0, 0);
        $sale("E2E-W{$worker}-PENDING-CARD", 'card', $card->id, 'pending', 888.0, 0);
        $sale("E2E-W{$worker}-CREDIT-TODAY", 'credit', null, 'paid', 75.0, 0);
        $sale("E2E-W{$worker}-CREDIT-YESTERDAY", 'credit', null, 'paid', 25.0, 1);

        DB::table('sales')->insert($rows);

        return [
            'recent' => $recent,
            'total' => count($recent) + 21,
            'todayTotal' => 150.0,
            'yesterdayTotal' => 200.0,
            'creditToday' => 75.0,
            'creditYesterday' => 25.0,
            'otherCardInvoice' => "E2E-W{$worker}-OTHER-CARD",
            'pendingInvoice' => "E2E-W{$worker}-PENDING-CARD",
        ];
    }

    private function wipeWallet(InventoryLocation $location): void
    {
        // Only seeded E2E sales exist at these stores; deleting them also clears their card type references.
        DB::table('sales')->where('location_id', $location->id)->delete();
        WalletCashCountSubmission::where('location_id', $location->id)->delete();
        WalletCashOpeningAudit::where('location_id', $location->id)->delete();
        WalletCashMovement::where('location_id', $location->id)->delete();
        WalletCashReconciliation::where('location_id', $location->id)->delete();
    }

    /** Adds a `wallet` section to tests/e2e/.fixtures.json (written by E2ESalesSeeder). */
    private function writeFixtureIds(array $workers): void
    {
        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $fixtures = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];

        $fixtures['wallet'] = [
            'workers' => $workers,
            'otherOrgLocationId' => InventoryLocation::where('code', 'MC-MAIN')->value('id'),
            'mainLocationId' => InventoryLocation::where('code', 'JB-MAIN')->value('id'),
            'mainCardTypeId' => PaymentCardType::where('domain', self::DOMAIN)->where('name', 'E2E Visa')->value('id'),
        ];

        file_put_contents($path, json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
