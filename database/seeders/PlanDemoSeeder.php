<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\ServiceTier;
use App\Models\User;
use App\Models\UserPin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * One demo organization per plan, with enough data to click through by hand:
 * demo-free, demo-basic, demo-standard and demo-premium.
 *
 *   php artisan db:seed --class="Database\Seeders\PlanDemoSeeder"
 *
 * Logins (password "password"): {role}@{slug}.test, e.g. admin@demo-basic.test,
 * manager@demo-standard.test, cashier2@demo-premium.test. PINs: admin 4567, managers 1234.
 *
 * Free sits at its limits (2 users, 1 store); Basic has 3 of its 5 users; Standard and Premium
 * have two stores and a warehouse. Sales go back 90 days, so Free's 31-day history shows. Standard
 * and Premium also get expenses. Re-running rebuilds the seeded sales, expenses and stock; users and
 * locations added by hand are kept.
 */
class PlanDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** Plan slug => organization slug (free has no plan). */
    public const ORGS = [
        'free' => 'demo-free',
        'basic' => 'demo-basic',
        'standard' => 'demo-standard',
        'premium' => 'demo-premium',
    ];

    /** [name, category, price, cost, stock]; stock 3 is under the reorder level, for low-stock alerts. */
    private const PRODUCTS = [
        ['Chicken Rice Meal', 'Meals', 120, 70, 80],
        ['Pork Adobo Meal', 'Meals', 135, 80, 60],
        ['Beef Tapa Meal', 'Meals', 150, 90, 40],
        ['Pancit Canton', 'Meals', 95, 50, 3],
        ['Iced Tea', 'Drinks', 45, 15, 200],
        ['Bottled Water', 'Drinks', 25, 10, 150],
        ['Brewed Coffee', 'Drinks', 60, 20, 120],
        ['French Fries', 'Snacks', 55, 20, 90],
        ['Turon', 'Snacks', 30, 10, 70],
        ['Halo-Halo', 'Snacks', 85, 35, 50],
    ];

    private const SALES = 40;

    private const SALES_DAYS = 90;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('PlanDemoSeeder may only run in local or testing environments.');
        }

        foreach (self::ORGS as $plan => $slug) {
            $tier = $plan === 'free' ? null : ServiceTier::query()->where('slug', $plan)->first();
            if ($plan !== 'free' && ! $tier) {
                throw new RuntimeException("The {$plan} plan is missing; run ServiceTierSeeder first.");
            }

            DB::transaction(fn () => $this->seedOrg($plan, $slug, $tier));
            $this->command?->info("Seeded {$slug} (admin@{$slug}.test / ".self::PASSWORD.')');
        }
    }

    private function seedOrg(string $plan, string $slug, ?ServiceTier $tier): void
    {
        $domain = Domain::query()->updateOrCreate(['name_slug' => $slug], [
            'name' => 'Demo '.ucfirst($plan), // the model derives name_slug (demo-{plan}) from this
            'timezone' => 'Asia/Manila',
            'country_code' => 'PH',
            'currency_code' => 'PHP',
            'date_format' => 'Y-m-d',
            'time_format' => '12h',
            'language_code' => 'en',
            'current_service_tier_id' => $tier?->id,
            'subscription_started_at' => $tier ? now() : null,
            'subscription_ends_at' => $tier ? now()->addYear() : null,
            'locked_at' => null,
            'is_active' => true,
        ]);
        // Only what the plan gives: nothing turned on by a super admin.
        $settings = $domain->settings ?? [];
        unset($settings['features']);
        $domain->update(['settings' => $settings]);

        $this->resetOrg($slug);
        $locations = $this->seedLocations($plan, $slug);
        $users = $this->seedUsers($plan, $slug, $locations);
        $products = $this->seedCatalog($plan, $slug, $locations);
        $this->seedSales($plan, $slug, $locations, $users, $products);
        if (in_array($plan, ['standard', 'premium'], true)) {
            $this->seedExpenses($slug, $locations, $users[0]);
        }
    }

    /** Removes what an earlier run seeded, so it can be rebuilt with fresh dates. */
    private function resetOrg(string $slug): void
    {
        DB::table('sales')->where('domain', $slug)->where('invoice_number', 'like', 'DEMO-%')->delete();
        DB::table('expenses')->where('domain', $slug)->where('reference_no', 'like', 'DEMO-%')->delete();
    }

    /** @return list<InventoryLocation> stores first, then the warehouse */
    private function seedLocations(string $plan, string $slug): array
    {
        $prefix = 'D'.strtoupper(substr($plan, 0, 3)); // codes hold 10 characters
        $rows = [["{$prefix}-MAIN", 'Main Store', 'store', true]];
        if (in_array($plan, ['standard', 'premium'], true)) {
            $rows[] = ["{$prefix}-MALL", 'Mall Branch', 'store', false];
            $rows[] = ["{$prefix}-WH", 'Warehouse', 'warehouse', false];
        }

        return array_map(fn ($row) => InventoryLocation::query()->updateOrCreate(['code' => $row[0]], [
            'domain' => $slug,
            'name' => $row[1],
            'type' => $row[2],
            'is_active' => true,
            'is_default' => $row[3],
        ]), $rows);
    }

    /**
     * @param  list<InventoryLocation>  $locations
     * @return list<User> the admin first
     */
    private function seedUsers(string $plan, string $slug, array $locations): array
    {
        // [email local part, role, role level, store index]
        $rows = match ($plan) {
            'free' => [['admin', 'admin', 2, 0], ['cashier', 'cashier', 5, 0]],
            'basic' => [['admin', 'admin', 2, 0], ['manager', 'manager', 3, 0], ['cashier', 'cashier', 5, 0]],
            default => [
                ['admin', 'admin', 2, 0],
                ['manager', 'manager', 3, 0], ['cashier', 'cashier', 5, 0],
                ['manager2', 'manager', 3, 1], ['cashier2', 'cashier', 5, 1],
            ],
        };

        $users = [];
        foreach ($rows as [$name, $role, $level, $store]) {
            $user = User::query()->updateOrCreate(['email' => "{$name}@{$slug}.test"], [
                'name' => 'Demo '.ucfirst($plan).' '.ucfirst($name),
                'password' => Hash::make(self::PASSWORD),
                'is_super_user' => false,
                'domain' => $slug,
                'role_level' => $level,
                'location_id' => $locations[$store]->id,
                'can_switch_locations' => $level <= 3,
                'email_verified_at' => now(),
            ]);
            $user->syncRoles([$role]);
            if ($role !== 'cashier') {
                UserPin::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    ['pin_code' => Hash::make($role === 'admin' ? '4567' : '1234'), 'active' => true],
                );
            }
            $users[] = $user;
        }

        return $users;
    }

    /**
     * @param  list<InventoryLocation>  $locations
     * @return list<Product>
     */
    private function seedCatalog(string $plan, string $slug, array $locations): array
    {
        $categories = [];
        foreach (['Meals', 'Drinks', 'Snacks'] as $name) {
            $category = Category::query()->updateOrCreate(['name' => $name, 'domain' => $slug], ['description' => 'Demo']);
            $category->locations()->syncWithoutDetaching(
                collect($locations)->mapWithKeys(fn ($l) => [$l->id => ['is_active' => true]])->all()
            );
            $categories[$name] = $category->id;
        }

        $tag = strtoupper($plan);
        $products = [];
        foreach (self::PRODUCTS as $i => [$name, $category, $price, $cost, $stock]) {
            $sku = sprintf('DEMO-%s-%02d', $tag, $i + 1);
            $product = Product::query()->updateOrCreate(['domain' => $slug, 'SKU' => $sku], [
                'name' => $name,
                'barcode' => $sku,
                'sold_type' => 'Piece',
                'price' => $price,
                'cost' => $cost,
                'category_id' => $categories[$category],
                'track_inventory' => true,
                'reorder_level' => 10,
                'representation_type' => 'color',
                'representation' => '94a3b8',
            ]);

            foreach ($locations as $location) {
                $product->addToLocation($location, true);
                // The warehouse holds the back stock; the low-stock item stays low everywhere.
                $qty = $location->type === 'warehouse' && $stock > 10 ? $stock * 3 : $stock;
                ProductInventory::query()->updateOrCreate(
                    ['product_id' => $product->id, 'location_id' => $location->id],
                    [
                        'quantity_on_hand' => $qty,
                        'quantity_reserved' => 0,
                        'quantity_available' => $qty,
                        'location_reorder_level' => 10,
                        'average_cost' => $cost,
                        'last_cost' => $cost,
                        'total_value' => $qty * $cost,
                    ],
                );
            }
            $products[] = $product;
        }

        return $products;
    }

    /**
     * Paid cash sales spread over the last 90 days, rung up by each store's own staff.
     *
     * @param  list<InventoryLocation>  $locations
     * @param  list<User>  $users
     * @param  list<Product>  $products
     */
    private function seedSales(string $plan, string $slug, array $locations, array $users, array $products): void
    {
        mt_srand(crc32($slug)); // the same sales every run
        $stores = array_values(array_filter($locations, fn ($l) => $l->type === 'store'));
        $now = now();

        for ($n = 1; $n <= self::SALES; $n++) {
            $store = $stores[$n % count($stores)];
            $staff = array_values(array_filter($users, fn ($u) => $u->location_id === $store->id));
            $user = $staff[$n % count($staff)];
            $at = $now->copy()
                ->subDays((int) floor(($n - 1) * self::SALES_DAYS / self::SALES))
                ->setTime(mt_rand(8, 20), mt_rand(0, 59));

            $lines = [];
            foreach ((array) array_rand($products, mt_rand(1, 3)) as $index) {
                $product = $products[$index];
                $qty = mt_rand(1, 3);
                $lines[] = [$product, $qty, (float) $product->price * $qty];
            }
            $total = array_sum(array_column($lines, 2));

            $saleId = DB::table('sales')->insertGetId([
                'domain' => $slug,
                'invoice_number' => sprintf('DEMO-%s-%03d', strtoupper($plan), $n),
                'user_id' => $user->id,
                'location_id' => $store->id,
                'total_amount' => $total,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'grand_total' => $total,
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'is_credit_sale' => false,
                'transaction_date' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            DB::table('sale_items')->insert(array_map(fn ($line) => [
                'sale_id' => $saleId,
                'product_id' => $line[0]->id,
                'quantity' => $line[1],
                'unit_price' => $line[0]->price,
                'discount' => 0,
                'subtotal' => $line[2],
                'created_at' => $at,
                'updated_at' => $at,
            ], $lines));
        }
    }

    /** @param  list<InventoryLocation>  $locations */
    private function seedExpenses(string $slug, array $locations, User $admin): void
    {
        ExpenseCategory::ensureDefaults($slug);
        $category = fn (string $name) => ExpenseCategory::query()->forDomain($slug)->where('name', $name)->value('id');

        // [category, description, payee, amount, days ago, payment method]
        $rows = [
            ['Rent', 'Store rent', 'Mall Leasing Inc.', 25000, 55, 'bank'],
            ['Utilities', 'Electricity', 'Meralco', 8200, 50, 'bank'],
            ['Utilities', 'Water', 'Maynilad', 1300, 48, 'ewallet'],
            ['Supplies', 'Cups, lids and bags', 'Packaging Depot', 3400, 40, 'cash_register'],
            ['Salaries & Wages', 'Staff payroll (1st half)', null, 36000, 31, 'bank'],
            ['Rent', 'Store rent', 'Mall Leasing Inc.', 25000, 25, 'bank'],
            ['Utilities', 'Electricity', 'Meralco', 7900, 20, 'bank'],
            ['Repairs & Maintenance', 'Freezer repair', 'CoolTech Services', 2500, 14, 'cash_register'],
            ['Marketing', 'Facebook ads', 'Meta', 1500, 9, 'card'],
            ['Supplies', 'Cleaning supplies', 'Puregold', 850, 3, 'cash_register'],
        ];

        foreach ($rows as $i => [$name, $description, $payee, $amount, $daysAgo, $method]) {
            $at = now()->subDays($daysAgo);
            Expense::query()->create([
                'domain' => $slug,
                'location_id' => $locations[$i % 2 === 1 && count($locations) > 1 ? 1 : 0]->id,
                'expense_category_id' => $category($name),
                'amount' => $amount,
                'expense_date' => $at->toDateString(),
                'description' => $description,
                'payee' => $payee,
                'payment_method' => $method,
                'reference_no' => sprintf('DEMO-EXP-%02d', $i + 1),
                'user_id' => $admin->id,
            ]);
        }
    }
}
