<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CreditTransaction;
use App\Models\InventoryLocation;
use App\Models\MandatoryDiscount;
use App\Models\PaymentCardType;
use App\Models\Product\Discount;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserPin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Fixtures for the Playwright Sales suite (tests/e2e/sales), all in jollibee-corp at JB-MAIN.
 *
 * Runs before every e2e run and resets stock, loyalty points, credit and pending carts, so
 * tests that complete real payments stay repeatable. Only records named "E2E ..." (and
 * e2e-* accounts) are created or reset; existing demo data is left alone, except that
 * pending (unpaid) carts of the accounts the suite signs in as are cleared.
 */
class E2ESalesSeeder extends Seeder
{
    public const DOMAIN = 'jollibee-corp';

    public const LOCATION_CODE = 'JB-MAIN';

    public const WORKER_CASHIERS = 4;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2ESalesSeeder may only run in local or testing environments.');
        }

        $location = InventoryLocation::where('code', self::LOCATION_CODE)->firstOrFail();

        DB::transaction(function () use ($location) {
            $cashiers = $this->seedCashiers($location);
            $this->seedProducts($location);
            $this->seedCustomers();
            $this->seedPaymentCardTypes($location);
            $this->seedDiscounts();
            $this->seedPins();
            $this->clearPendingCarts($cashiers);
        });

        $this->writeFixtureIds();
    }

    /**
     * Writes record IDs for the Playwright suite (git-ignored), so tests don't have to look
     * up IDs the app never exposes, like inactive card types or another org's records.
     */
    private function writeFixtureIds(): void
    {
        $ids = fn ($query) => $query->pluck('id', 'name');
        $userIds = fn (array $emails) => User::whereIn('email', $emails)->pluck('id', 'email');

        $fixtures = [
            'products' => $ids(Product::where('domain', self::DOMAIN)->where('name', 'like', 'E2E %')),
            'customers' => $ids(Customer::where('domain', self::DOMAIN)->where('email', 'like', 'e2e-%@techiko.test')),
            'cardTypes' => $ids(PaymentCardType::where('name', 'like', 'E2E %')),
            'discounts' => $ids(Discount::where('domain', self::DOMAIN)->where('name', 'like', 'E2E %')),
            'mandatoryDiscounts' => $ids(MandatoryDiscount::where('domain', self::DOMAIN)->where('name', 'like', 'E2E %')),
            'users' => $userIds([
                ...array_map(fn ($i) => "e2e-cashier-{$i}@techiko.test", range(1, self::WORKER_CASHIERS)),
                'admin@jollibee-corp.com',
                'manager@jollibee-corp.com',
                'cashier1@jollibee-corp.com',
                'cashier1@mcdonalds-corp.com',
                'e2e-no-dashboard@techiko.test',
            ]),
            'otherOrg' => [
                'domain' => 'mcdonalds-corp',
                'productId' => Product::where('domain', 'mcdonalds-corp')->value('id'),
                'customerId' => Customer::where('domain', 'mcdonalds-corp')->value('id'),
                'discountId' => Discount::where('domain', 'mcdonalds-corp')->value('id'),
            ],
        ];

        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        file_put_contents($path, json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** One cashier per Playwright worker: each user has their own pending cart. */
    private function seedCashiers(InventoryLocation $location): array
    {
        $emails = [];

        for ($i = 1; $i <= self::WORKER_CASHIERS; $i++) {
            $user = User::updateOrCreate(
                ['email' => "e2e-cashier-{$i}@techiko.test"],
                [
                    'name' => "E2E Cashier {$i}",
                    'password' => Hash::make(E2EUserSeeder::PASSWORD),
                    'is_super_user' => false,
                    'domain' => self::DOMAIN,
                    'role_level' => 5,
                    'location_id' => $location->id,
                    'can_switch_locations' => false,
                    'email_verified_at' => now(),
                ]
            );
            $user->syncRoles(['cashier']);
            $emails[] = $user->email;
        }

        return $emails;
    }

    private function seedProducts(InventoryLocation $location): void
    {
        $category = Category::updateOrCreate(
            ['name' => 'E2E', 'domain' => self::DOMAIN],
            ['description' => 'Playwright test products']
        );

        $products = [
            ['name' => 'E2E Burger', 'price' => 100, 'stock' => 1000, 'barcode' => 'E2E000001'],
            ['name' => 'E2E Fries', 'price' => 50, 'stock' => 1000, 'barcode' => 'E2E000002'],
            ['name' => 'E2E Limited', 'price' => 80, 'stock' => 2, 'barcode' => 'E2E000003'],
            ['name' => 'E2E Sold Out', 'price' => 60, 'stock' => 0, 'barcode' => 'E2E000004'],
            // Only security.spec.js uses this, so cross-cart probes can't disturb other tests' carts.
            ['name' => 'E2E Security Probe', 'price' => 10, 'stock' => 1000, 'barcode' => 'E2E000005'],
        ];

        foreach ($products as $row) {
            $product = Product::updateOrCreate(
                ['domain' => self::DOMAIN, 'name' => $row['name']],
                [
                    'sold_type' => 'piece',
                    'price' => $row['price'],
                    'cost' => $row['price'] / 2,
                    'SKU' => str_replace(' ', '-', strtoupper($row['name'])),
                    'barcode' => $row['barcode'],
                    'category_id' => $category->id,
                    'track_inventory' => true,
                    'reorder_level' => 0,
                    'unit_of_measure' => 'piece',
                    'stock_status' => $row['stock'] > 0 ? 'in_stock' : 'out_of_stock',
                ]
            );

            DB::table('location_product')->updateOrInsert(
                ['location_id' => $location->id, 'product_id' => $product->id],
                ['is_active' => true, 'updated_at' => now(), 'created_at' => now()]
            );

            ProductInventory::updateOrCreate(
                ['product_id' => $product->id, 'location_id' => $location->id],
                [
                    'quantity_on_hand' => $row['stock'],
                    'quantity_reserved' => 0,
                    'quantity_available' => $row['stock'],
                    'location_reorder_level' => 0,
                    'average_cost' => $row['price'] / 2,
                    'last_cost' => $row['price'] / 2,
                    'total_value' => $row['stock'] * $row['price'] / 2,
                ]
            );
        }
    }

    private function seedCustomers(): void
    {
        $customers = [
            [
                'name' => 'E2E Loyalty Customer',
                'email' => 'e2e-loyalty@techiko.test',
                'phone' => '09000000001',
                'loyalty_points' => 5000,
                'credit_enabled' => false,
                'credit_limit' => 0,
            ],
            [
                'name' => 'E2E Credit Customer',
                'email' => 'e2e-credit@techiko.test',
                'phone' => '09000000002',
                'loyalty_points' => 0,
                'credit_enabled' => true,
                'credit_limit' => 500,
            ],
            [
                'name' => 'E2E No Credit Customer',
                'email' => 'e2e-no-credit@techiko.test',
                'phone' => '09000000003',
                'loyalty_points' => 0,
                'credit_enabled' => false,
                'credit_limit' => 0,
            ],
        ];

        foreach ($customers as $row) {
            $customer = Customer::updateOrCreate(
                ['email' => $row['email']],
                [
                    ...$row,
                    ...Customer::defaultLoyaltyData(),
                    'loyalty_points' => $row['loyalty_points'],
                    'domain' => self::DOMAIN,
                    'credit_balance' => 0,
                    'credit_terms_days' => 30,
                ]
            );

            CreditTransaction::where('customer_id', $customer->id)->delete();
        }

        // Customers created by tests (checkout.spec.js, security.spec.js), in any organization.
        Customer::where('email', 'like', 'e2e-new-%@techiko.test')->delete();
    }

    private function seedPaymentCardTypes(InventoryLocation $location): void
    {
        PaymentCardType::updateOrCreate(
            ['domain' => self::DOMAIN, 'name' => 'E2E Visa'],
            ['is_active' => true, 'location_id' => $location->id, 'sort_order' => 0]
        );

        PaymentCardType::updateOrCreate(
            ['domain' => self::DOMAIN, 'name' => 'E2E Inactive Card'],
            ['is_active' => false, 'location_id' => $location->id, 'sort_order' => 99]
        );

        // Another organization's card type, for cross-organization checks.
        $otherLocation = InventoryLocation::where('code', 'MC-MAIN')->first();
        if ($otherLocation) {
            PaymentCardType::updateOrCreate(
                ['domain' => $otherLocation->domain, 'name' => 'E2E McDonalds Card'],
                ['is_active' => true, 'location_id' => $otherLocation->id, 'sort_order' => 0]
            );
        }
    }

    private function seedDiscounts(): void
    {
        Discount::updateOrCreate(
            ['domain' => self::DOMAIN, 'name' => 'E2E 10% Order'],
            [
                'type' => 'percent',
                'scope' => 'order',
                'value' => 10,
                'min_order_amount' => null,
                'start_date' => null,
                'end_date' => null,
                'is_mandatory' => false,
                'is_active' => true,
            ]
        );

        Discount::updateOrCreate(
            ['domain' => self::DOMAIN, 'name' => 'E2E ₱20 Item'],
            [
                'type' => 'amount',
                'scope' => 'product',
                'value' => 20,
                'min_order_amount' => null,
                'start_date' => null,
                'end_date' => null,
                'is_mandatory' => false,
                'is_active' => true,
            ]
        );

        foreach (['E2E Senior 20%', 'E2E PWD 20%'] as $name) {
            MandatoryDiscount::updateOrCreate(
                ['domain' => self::DOMAIN, 'name' => $name],
                ['type' => 'percentage', 'value' => 20, 'is_active' => true]
            );
        }
    }

    /** Void approvals: manager 1234, admin 4567 (same as UserPinSeeder). */
    private function seedPins(): void
    {
        $pins = [
            'manager@jollibee-corp.com' => '1234',
            'admin@jollibee-corp.com' => '4567',
        ];

        foreach ($pins as $email => $pin) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                continue;
            }

            $userPin = UserPin::firstOrNew(['user_id' => $user->id]);
            $userPin->pin_code = Hash::make($pin);
            $userPin->active = true;
            $userPin->save();
        }
    }

    /** Deleting a sale cascades to its items, discounts and void logs. */
    private function clearPendingCarts(array $cashierEmails): void
    {
        $emails = [
            ...$cashierEmails,
            'admin@jollibee-corp.com',
            'manager@jollibee-corp.com',
            'cashier1@jollibee-corp.com',
            'cashier1@mcdonalds-corp.com',
            'e2e-no-dashboard@techiko.test',
        ];

        Sale::where('payment_status', 'pending')
            ->whereIn('user_id', User::whereIn('email', $emails)->pluck('id'))
            ->delete();
    }
}
