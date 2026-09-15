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

                $workers[$i] = [
                    'locationId' => $location->id,
                    'locationName' => $location->name,
                    'managerEmail' => $manager->email,
                    'managerName' => $manager->name,
                    'partnerEmail' => $partner->email,
                    'partnerName' => $partner->name,
                    'cardTypeId' => $card->id,
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

    private function wipeWallet(InventoryLocation $location): void
    {
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
