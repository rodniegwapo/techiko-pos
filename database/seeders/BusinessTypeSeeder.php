<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\ModifierGroup;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * One organization per business type (Settings → Business type), for trying each one and for
 * tests/e2e/business-types: pos-retail, pos-food, pos-grocery, pos-services and pos-other. Each has
 * one store, an admin ({slug}-admin@techiko.test, E2EUserSeeder::PASSWORD; retail also a manager) and products that fit
 * it: food has Size and Sugar level modifiers and dine-in, grocery sells by the kilo and liter,
 * services track no stock. They are VIP, so every feature is open. Re-running puts each back;
 * products a test added are removed. Local and testing only.
 *
 * php artisan db:seed --class=BusinessTypeSeeder
 */
class BusinessTypeSeeder extends Seeder
{
    /** Business type => organization name. */
    public const ORGS = [
        'retail' => 'Demo Sari-Sari Store',
        'food' => 'Demo Milk Tea Café',
        'grocery' => 'Demo Wet Market Stall',
        'services' => 'Demo Barber Shop',
        'other' => 'Demo Mixed Store',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('BusinessTypeSeeder may only run in local or testing environments.');
        }

        foreach (self::ORGS as $type => $name) {
            DB::transaction(fn () => $this->seedOrg($type, $name));
            $this->command?->info("pos-{$type}: {$name} (pos-{$type}-admin@techiko.test)");
        }
    }

    private function seedOrg(string $type, string $name): void
    {
        $slug = "pos-{$type}";
        // Setting a new organization's name also sets its slug from the name, so the slug goes after it.
        $domain = Domain::query()->firstOrNew(['name_slug' => $slug]);
        $domain->name = $name;
        $domain->name_slug = $slug;
        $domain->fill(['is_active' => true, 'locked_at' => null, 'vip_at' => $domain->vip_at ?? now()])->save();
        $settings = $domain->settings ?? [];
        // Its type, as set at signup: menu switch off and no change recorded.
        $sales = array_merge($settings['sales'] ?? [], ['business_type' => $type, 'show_all_menu' => false]);
        unset($sales['business_type_changed_by'], $sales['business_type_changed_at'], $sales['business_type_previous']);
        $settings['sales'] = $sales;
        $domain->update(['settings' => $settings]);

        // Codes hold 10 characters: POS-RETA, POS-FOOD, POS-GROC, POS-SERV, POS-OTHE.
        $store = InventoryLocation::query()->updateOrCreate(['code' => 'POS-'.strtoupper(substr($type, 0, 4))], [
            'domain' => $slug,
            'name' => $name,
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
            'dine_in' => $type === 'food',
        ]);

        $admin = User::query()->updateOrCreate(['email' => "{$slug}-admin@techiko.test"], [
            'name' => $name.' Admin',
            'password' => Hash::make(E2EUserSeeder::PASSWORD),
            'is_super_user' => false,
            'domain' => $slug,
            'role_level' => 2,
            'location_id' => $store->id,
            'can_switch_locations' => true,
            'email_verified_at' => now(),
        ]);
        $admin->syncRoles(['admin']);

        // A manager too, at the store: they see the business type but only an owner or admin changes it.
        if ($type === 'retail') {
            User::query()->updateOrCreate(['email' => "{$slug}-manager@techiko.test"], [
                'name' => $name.' Manager',
                'password' => Hash::make(E2EUserSeeder::PASSWORD),
                'is_super_user' => false,
                'domain' => $slug,
                'role_level' => 3,
                'location_id' => $store->id,
                'can_switch_locations' => false,
                'email_verified_at' => now(),
            ])->syncRoles(['manager']);
        }

        // Products and tables a test created (a sale's table link is cleared with the table).
        Product::query()->where('domain', $slug)->where('name', 'like', 'E2E %')->get()->each->delete();
        DiningTable::query()->where('location_id', $store->id)->delete();

        foreach ($this->catalog($type) as $categoryName => $products) {
            $category = Category::query()->updateOrCreate(['name' => $categoryName, 'domain' => $slug], ['description' => 'Demo']);
            foreach ($products as $i => [$productName, $soldType, $price, $cost, $qty, $tracked]) {
                $this->product($slug, $store, $category, $productName, $soldType, $price, $cost, $qty, $tracked, sprintf('%s-%s-%02d', strtoupper($type), substr(md5($categoryName), 0, 4), $i));
            }
        }

        if ($type === 'food' || $type === 'other') {
            $this->modifiers($slug, $type === 'food' ? ['Classic Milk Tea', 'Wintermelon Milk Tea', 'Iced Coffee'] : ['Iced Coffee']);
        }
    }

    /**
     * Category => [name, sold type, price, cost, stock, track stock].
     *
     * @return array<string, list<array{0: string, 1: string, 2: float, 3: ?float, 4: float, 5: bool}>>
     */
    private function catalog(string $type): array
    {
        return match ($type) {
            'retail' => [
                'Drinks' => [['Coke Mismo', 'Piece', 20, 15, 48, true], ['Bottled Water 500ml', 'Piece', 15, 9, 60, true]],
                'Snacks' => [['Piattos', 'Pack', 25, 19, 30, true], ['Skyflakes', 'Pack', 10, 7, 40, true]],
                'Canned goods' => [['Century Tuna', 'Piece', 38, 31, 24, true], ['Sardines (box of 10)', 'Box', 220, 180, 5, true]],
            ],
            'food' => [
                'Milk tea' => [['Classic Milk Tea', 'Piece', 89, null, 0, false], ['Wintermelon Milk Tea', 'Piece', 99, null, 0, false]],
                'Coffee' => [['Iced Coffee', 'Piece', 79, null, 0, false]],
                'Snacks' => [['Fries', 'Piece', 59, null, 0, false], ['Bottled Water', 'Piece', 20, 9, 24, true]],
            ],
            'grocery' => [
                'Meat' => [['Pork Liempo', 'Kilo', 340, 290, 12.5, true], ['Chicken Breast', 'Kilo', 220, 180, 8, true]],
                'Rice' => [['Dinorado Rice', 'Kilo', 58, 48, 50, true]],
                'Liquids' => [['Cooking Oil', 'Liter', 120, 98, 20, true], ['Vinegar', 'Liter', 45, 32, 10, true]],
                'Eggs' => [['Eggs (dozen)', 'Dozen', 110, 90, 15, true]],
            ],
            'services' => [
                'Haircuts' => [['Haircut', 'Piece', 150, null, 0, false], ['Haircut + Shave', 'Piece', 220, null, 0, false]],
                // Sold from the counter without counting stock, so the shop's menu stays a services one.
                'Add-ons' => [['Hair Wax', 'Piece', 120, 80, 0, false]],
            ],
            default => [
                'Store' => [['Coke Mismo', 'Piece', 20, 15, 48, true], ['Dinorado Rice', 'Kilo', 58, 48, 50, true]],
                'Café' => [['Iced Coffee', 'Piece', 79, null, 0, false]],
                'Services' => [['Phone Load Service', 'Piece', 5, null, 0, false]],
            ],
        };
    }

    private function product(string $slug, InventoryLocation $store, Category $category, string $name, string $soldType, float $price, ?float $cost, float $qty, bool $tracked, string $code): Product
    {
        $product = Product::query()->updateOrCreate(['domain' => $slug, 'SKU' => $code], [
            'name' => $name,
            'barcode' => $code,
            'sold_type' => $soldType,
            'price' => $price,
            'cost' => $cost,
            'category_id' => $category->id,
            'track_inventory' => $tracked,
            'reorder_level' => $tracked ? 2 : 0,
            'representation_type' => 'color',
            'representation' => null,
        ]);
        $product->addToLocation($store, true);

        ProductInventory::query()->updateOrCreate(['product_id' => $product->id, 'location_id' => $store->id], [
            'quantity_on_hand' => $qty,
            'quantity_reserved' => 0,
            'quantity_available' => $qty,
            'location_reorder_level' => null,
            'average_cost' => $cost ?? 0,
            'last_cost' => $cost ?? 0,
            'total_value' => $qty * ($cost ?? 0),
        ]);

        return $product;
    }

    /** Size (pick one, required) and Sugar level (pick one) on the drinks. */
    private function modifiers(string $slug, array $drinks): void
    {
        $groups = [
            ['Size', true, [['Regular', 0], ['Large', 20]]],
            ['Sugar level', false, [['100%', 0], ['50%', 0], ['No sugar', 0]]],
        ];
        $ids = [];
        foreach ($groups as $order => [$groupName, $required, $options]) {
            $group = ModifierGroup::query()->updateOrCreate(
                ['domain' => $slug, 'name' => $groupName],
                ['selection' => 'single', 'is_required' => $required, 'max_select' => null, 'sort_order' => $order],
            );
            foreach ($options as $i => [$optionName, $delta]) {
                $group->modifiers()->updateOrCreate(
                    ['name' => $optionName],
                    ['price_delta' => $delta, 'cost_delta' => 0, 'is_active' => true, 'sort_order' => $i],
                );
            }
            $ids[$group->id] = ['sort_order' => $order];
        }

        Product::query()->where('domain', $slug)->whereIn('name', $drinks)->get()
            ->each(fn (Product $drink) => $drink->modifierGroups()->sync($ids));
    }
}
