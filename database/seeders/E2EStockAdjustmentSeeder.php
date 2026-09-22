<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixtures for the Playwright stock adjustments suite (tests/e2e/inventory/adjustments.spec.js).
 * Runs after E2EInventorySeeder.
 *
 * Two inactive stores, so the read-only assertions and the approval workflow can't disturb
 * each other or the inventory dashboard fixtures:
 *
 * - E2E-ADJ holds 24 adjustments (one per status plus 20 fillers) with exact numbers, reasons,
 *   item counts and values, for the list, search, status filter and pagination tests. The
 *   approved and rejected rows are written directly, so no stock moves and the store's
 *   quantities stay as seeded.
 * - E2E-ADJ-FLOW starts with no adjustments. The workflow tests create their own there over the
 *   API and then submit/approve/reject/delete them, which does move that store's stock.
 *
 * Both stores are inactive so they don't show up in other suites' store pickers. Rebuilt every
 * run. Local/testing only.
 */
class E2EStockAdjustmentSeeder extends Seeder
{
    public const DOMAIN = 'jollibee-corp';

    private const LIST_STORE = 'E2E-ADJ';

    // `inventory_locations.code` is varchar(10).
    private const FLOW_STORE = 'E2E-ADJF';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EStockAdjustmentSeeder may only run in local or testing environments.');
        }

        $fixtures = DB::transaction(function () {
            $this->reset();

            $listStore = $this->store(self::LIST_STORE, 'E2E Adjustments Store');
            $flowStore = $this->store(self::FLOW_STORE, 'E2E Adjustment Flow Store');

            $category = Category::updateOrCreate(
                ['name' => 'E2E Adj Supplies', 'domain' => self::DOMAIN],
                ['description' => 'Playwright']
            );

            // name, sku, quantity, cost — the same two products live in both stores.
            $widget = $this->product('E2E Adj Widget', 'E2E-ADJ-WIDGET', $category->id, 12.5, [
                [$listStore, 40], [$flowStore, 40],
            ]);
            $gadget = $this->product('E2E Adj Gadget', 'E2E-ADJ-GADGET', $category->id, 5, [
                [$listStore, 20], [$flowStore, 20],
            ]);

            $admin = User::where('email', 'admin@'.self::DOMAIN.'.com')->firstOrFail();

            return [
                'listStore' => $this->row($listStore),
                'flowStore' => $this->row($flowStore),
                'products' => [
                    'widget' => ['id' => $widget->id, 'name' => $widget->name, 'sku' => $widget->SKU, 'cost' => 12.5, 'qty' => 40],
                    'gadget' => ['id' => $gadget->id, 'name' => $gadget->name, 'sku' => $gadget->SKU, 'cost' => 5, 'qty' => 20],
                ],
                'adjustments' => $this->adjustments($listStore, $admin, $widget, $gadget),
                'perPage' => 20,
                'createdBy' => ['id' => $admin->id, 'name' => $admin->name],
                'mainLocation' => InventoryLocation::where('code', 'JB-MAIN')->first(['id', 'name', 'code'])->toArray(),
                'otherOrgLocationId' => InventoryLocation::where('code', 'MC-MAIN')->value('id'),
            ];
        });

        $path = env('E2E_FIXTURES_FILE', base_path('tests/e2e/.fixtures.json'));
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $all['stockAdjustments'] = $fixtures;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Clears both stores. Order matters: adjustment items and movements reference the products,
     * which may not be deleted while those rows point at them.
     */
    private function reset(): void
    {
        $storeIds = InventoryLocation::whereIn('code', [self::LIST_STORE, self::FLOW_STORE])->pluck('id');

        if ($storeIds->isNotEmpty()) {
            // Items cascade with their adjustment.
            StockAdjustment::whereIn('location_id', $storeIds)->delete();
            InventoryMovement::whereIn('location_id', $storeIds)->delete();
            ProductInventory::whereIn('location_id', $storeIds)->delete();
        }

        $productIds = Product::where('SKU', 'like', 'E2E-ADJ-%')->pluck('id');
        if ($productIds->isNotEmpty()) {
            // Adjustments made by earlier runs at other stores would block the product delete.
            StockAdjustment::whereIn('id', StockAdjustmentItem::whereIn('product_id', $productIds)->pluck('stock_adjustment_id'))->delete();
            InventoryMovement::whereIn('product_id', $productIds)->delete();
        }
        Product::whereIn('id', $productIds)->delete();
    }

    private function store(string $code, string $name): InventoryLocation
    {
        return InventoryLocation::updateOrCreate(
            ['code' => $code],
            [
                'domain' => self::DOMAIN,
                'name' => $name,
                'type' => 'store',
                'address' => '2 E2E Street',
                'is_active' => false,
                'is_default' => false,
                'notes' => 'Playwright stock adjustment tests only',
            ]
        );
    }

    /**
     * @param  array<int, array{0: InventoryLocation, 1: int}>  $stock
     */
    private function product(string $name, string $sku, int $categoryId, float $cost, array $stock): Product
    {
        $product = Product::create([
            'domain' => self::DOMAIN,
            'name' => $name,
            'SKU' => $sku,
            'barcode' => $sku,
            'sold_type' => 'Piece',
            'price' => $cost * 2,
            'cost' => $cost,
            'category_id' => $categoryId,
            'track_inventory' => true,
            'reorder_level' => 5,
            'representation_type' => 'color',
            'representation' => '94a3b8',
        ]);

        foreach ($stock as [$location, $qty]) {
            $product->addToLocation($location, true);

            ProductInventory::create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'quantity_on_hand' => $qty,
                'quantity_reserved' => 0,
                'quantity_available' => $qty,
                'average_cost' => $cost,
                'last_cost' => $cost,
                'total_value' => $qty * $cost,
            ]);
        }

        return $product;
    }

    /**
     * The list store's adjustments, newest first: one per status with known values, then 20 filler
     * drafts so the 20-per-page list has a page 2.
     *
     * @return array<string, mixed>
     */
    private function adjustments(InventoryLocation $store, User $admin, Product $widget, Product $gadget): array
    {
        // Two items, so the Items column and the details modal have more than one row to show.
        $draft = $this->adjustment($store, $admin, 'E2E-ADJ-0001', 'recount', 'physical_count', 'E2E draft recount', 'draft', '2026-04-04 09:00:00', [
            [$widget, 40, 44],
            [$gadget, 20, 18],
        ]);
        $pending = $this->adjustment($store, $admin, 'E2E-ADJ-0002', 'decrease', 'damaged_goods', 'E2E pending damage', 'pending_approval', '2026-04-03 09:00:00', [
            [$gadget, 20, 15],
        ]);
        $approved = $this->adjustment($store, $admin, 'E2E-ADJ-0003', 'decrease', 'expired_goods', 'E2E approved expiry', 'approved', '2026-04-02 09:00:00', [
            [$gadget, 20, 19],
        ], $admin);
        $rejected = $this->adjustment($store, $admin, 'E2E-ADJ-0004', 'decrease', 'theft_loss', 'E2E rejected theft', 'rejected', '2026-04-01 09:00:00', [
            [$widget, 40, 35],
        ]);

        for ($i = 1; $i <= 20; $i++) {
            $this->adjustment(
                $store,
                $admin,
                sprintf('E2E-ADJ-F%03d', $i),
                'increase',
                'other',
                sprintf('E2E filler adjustment %d', $i),
                'draft',
                sprintf('2026-03-%02d 09:00:00', 21 - $i),
                [[$widget, 40, 41]]
            );
        }

        $row = fn (StockAdjustment $a, int $items, float $value, string $reason) => [
            'id' => $a->id,
            'number' => $a->adjustment_number,
            'items' => $items,
            'value' => $value,
            'reason' => $reason,
            'description' => $a->description,
        ];

        return [
            'total' => 24,
            'draftCount' => 21,
            // +4 widgets at ₱12.50 and -2 gadgets at ₱5.00.
            'draft' => $row($draft, 2, 40, 'Physical Count'),
            'pending' => $row($pending, 1, -25, 'Damaged Goods'),
            'approved' => $row($approved, 1, -5, 'Expired Goods'),
            'rejected' => $row($rejected, 1, -62.5, 'Theft/Loss'),
            // Fillers are numbered newest first, so page 1 ends at F016 and page 2 starts at F017.
            'firstFiller' => 'E2E-ADJ-F001',
            'page2First' => 'E2E-ADJ-F017',
            'lastFiller' => 'E2E-ADJ-F020',
        ];
    }

    /**
     * @param  array<int, array{0: Product, 1: int, 2: int}>  $items  [product, system quantity, actual quantity]
     */
    private function adjustment(
        InventoryLocation $store,
        User $creator,
        string $number,
        string $type,
        string $reason,
        string $description,
        string $status,
        string $createdAt,
        array $items,
        ?User $approver = null
    ): StockAdjustment {
        $adjustment = StockAdjustment::create([
            'domain' => self::DOMAIN,
            'adjustment_number' => $number,
            'location_id' => $store->id,
            'type' => $type,
            'reason' => $reason,
            'description' => $description,
            'status' => $status,
            'created_by' => $creator->id,
            'approved_by' => $approver?->id,
            'approved_at' => $approver ? $createdAt : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        foreach ($items as [$product, $systemQuantity, $actualQuantity]) {
            StockAdjustmentItem::create([
                'stock_adjustment_id' => $adjustment->id,
                'product_id' => $product->id,
                'system_quantity' => $systemQuantity,
                'actual_quantity' => $actualQuantity,
                'unit_cost' => $product->cost,
                'notes' => "E2E {$product->SKU} line",
            ]);
        }

        // Written directly, so an approved fixture doesn't move any stock.
        $adjustment->calculateTotalValueChange();

        return $adjustment->refresh();
    }

    /** @return array<string, mixed> */
    private function row(InventoryLocation $store): array
    {
        return ['id' => $store->id, 'name' => $store->name, 'code' => $store->code];
    }
}
