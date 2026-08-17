<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Services\InventoryService;
use Database\Seeders\ProductSoldTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryServiceAssignedLocationScopeTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSoldTypeSeeder::class);
        $this->inventoryService = app(InventoryService::class);
    }

    /**
     * @return array{domain: Domain, locA: InventoryLocation, locB: InventoryLocation, category: Category}
     */
    private function seedDomainTwoLocations(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Inv Scope Org',
            'name_slug' => 'inv-scope-'.Str::lower(Str::random(8)),
        ]);

        $locA = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store A',
            'code' => 'A-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $locB = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store B',
            'code' => 'B-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => false,
        ]);

        $category = Category::factory()->create([
            'domain' => $domain->name_slug,
        ]);

        return compact('domain', 'locA', 'locB', 'category');
    }

    private function sumChartSkus(array $categoryStockData): int
    {
        $sum = 0;
        foreach ($categoryStockData as $row) {
            $sum += (int) ($row['in_stock'] ?? 0);
            $sum += (int) ($row['low_stock'] ?? 0);
            $sum += (int) ($row['out_of_stock'] ?? 0);
        }

        return $sum;
    }

    public function test_category_chart_for_location_b_excludes_products_assigned_only_to_a(): void
    {
        $ctx = $this->seedDomainTwoLocations();

        $onlyA = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'track_inventory' => true,
            'barcode' => 'ONLY-A-'.Str::random(8),
        ]);
        $onlyA->activeLocations()->attach($ctx['locA']->id, ['is_active' => true]);

        $onlyB = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'track_inventory' => true,
            'barcode' => 'ONLY-B-'.Str::random(8),
        ]);
        $onlyB->activeLocations()->attach($ctx['locB']->id, ['is_active' => true]);

        $chartB = $this->inventoryService->getCategoryStockData($ctx['locB'], $ctx['domain']->name_slug);
        $this->assertSame(1, $this->sumChartSkus($chartB), 'Store B chart should count only SKU assigned to B');

        $chartA = $this->inventoryService->getCategoryStockData($ctx['locA'], $ctx['domain']->name_slug);
        $this->assertSame(1, $this->sumChartSkus($chartA), 'Store A chart should count only SKU assigned to A');
    }

    public function test_inventory_report_summary_stock_kpis_match_assigned_product_statuses(): void
    {
        $ctx = $this->seedDomainTwoLocations();

        $atB = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'track_inventory' => true,
            'reorder_level' => 5,
            'barcode' => 'AT-B-'.Str::random(8),
        ]);
        $atB->activeLocations()->attach($ctx['locB']->id, ['is_active' => true]);

        ProductInventory::query()->create([
            'product_id' => $atB->id,
            'location_id' => $ctx['locB']->id,
            'quantity_on_hand' => 100,
            'quantity_reserved' => 0,
            'quantity_available' => 100,
        ]);

        $report = $this->inventoryService->getInventoryReport($ctx['locB'], $ctx['domain']->name_slug);

        $this->assertSame(1, $report['summary']['total_products']);
        $this->assertSame(1, $report['summary']['in_stock_products']);
        $this->assertSame(0, $report['summary']['low_stock_products']);
        $this->assertSame(0, $report['summary']['out_of_stock_products']);

        $chartSum = $this->sumChartSkus($report['category_stock_data']);
        $this->assertSame(
            $report['summary']['in_stock_products']
                + $report['summary']['low_stock_products']
                + $report['summary']['out_of_stock_products'],
            $chartSum
        );
    }

    public function test_assigned_product_without_inventory_row_counts_as_out_of_stock_in_summary_and_chart(): void
    {
        $ctx = $this->seedDomainTwoLocations();

        $atB = Product::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'category_id' => $ctx['category']->id,
            'sold_type' => 'Piece',
            'track_inventory' => true,
            'barcode' => 'NO-ROW-'.Str::random(8),
        ]);
        $atB->activeLocations()->attach($ctx['locB']->id, ['is_active' => true]);

        $report = $this->inventoryService->getInventoryReport($ctx['locB'], $ctx['domain']->name_slug);

        $this->assertSame(0, $report['summary']['total_products']);
        $this->assertSame(0, $report['summary']['in_stock_products']);
        $this->assertSame(0, $report['summary']['low_stock_products']);
        $this->assertSame(1, $report['summary']['out_of_stock_products']);

        $chartB = $this->inventoryService->getCategoryStockData($ctx['locB'], $ctx['domain']->name_slug);
        $this->assertSame(1, $this->sumChartSkus($chartB));
        $this->assertSame(1, $chartB[0]['out_of_stock'] ?? null);
    }
}
