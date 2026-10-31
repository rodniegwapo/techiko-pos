<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The organization tests/e2e/settings changes: e2e-settings, with one store, an admin, a manager
 * and one product. Settings belong to the whole organization, so it has its own and other specs
 * never see them change. Re-running puts every setting back to how a new organization starts
 * (pesos, no VAT, no business type picked).
 */
class E2ESettingsSeeder extends Seeder
{
    public const SLUG = 'e2e-settings';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2ESettingsSeeder may only run in local or testing environments.');
        }

        DB::transaction(function () {
            // Setting a new organization's name also sets its slug, so the slug goes after it.
            $domain = Domain::query()->firstOrNew(['name_slug' => self::SLUG]);
            $domain->name = 'E2E Settings Org';
            $domain->name_slug = self::SLUG;
            $domain->fill([
                'is_active' => true,
                'locked_at' => null,
                'vip_at' => $domain->vip_at ?? now(),
                'currency_code' => 'PHP',
                'settings' => [],
            ])->save();

            $store = InventoryLocation::query()->updateOrCreate(['code' => 'E2E-SET'], [
                'domain' => self::SLUG,
                'name' => 'E2E Settings Store',
                'type' => 'store',
                'is_active' => true,
                'is_default' => true,
            ]);
            InventoryLocation::query()->where('domain', self::SLUG)->whereKeyNot($store->id)->delete();

            foreach ([['admin', 2], ['manager', 3]] as [$role, $level]) {
                User::query()->updateOrCreate(['email' => self::SLUG."-{$role}@techiko.test"], [
                    'name' => 'E2E Settings '.ucfirst($role),
                    'password' => Hash::make(E2EUserSeeder::PASSWORD),
                    'is_super_user' => false,
                    'domain' => self::SLUG,
                    'role_level' => $level,
                    'location_id' => $store->id,
                    'can_switch_locations' => $level <= 2,
                    'email_verified_at' => now(),
                ])->syncRoles([$role]);
            }

            $category = Category::query()->updateOrCreate(['name' => 'E2E Settings', 'domain' => self::SLUG], ['description' => 'Playwright']);
            $product = Product::query()->updateOrCreate(['domain' => self::SLUG, 'SKU' => 'E2E-SET-1'], [
                'name' => 'E2E Settings Soap',
                'barcode' => 'E2E-SET-BAR-1',
                'sold_type' => 'Piece',
                'price' => 125,
                'cost' => 80,
                'category_id' => $category->id,
                'track_inventory' => true,
                'reorder_level' => 0,
                'representation_type' => 'color',
                'representation' => null,
            ]);
            $product->addToLocation($store, true);
            ProductInventory::query()->updateOrCreate(['product_id' => $product->id, 'location_id' => $store->id], [
                'quantity_on_hand' => 10,
                'quantity_reserved' => 0,
                'quantity_available' => 10,
                'average_cost' => 80,
                'last_cost' => 80,
                'total_value' => 800,
            ]);
        });
    }
}
