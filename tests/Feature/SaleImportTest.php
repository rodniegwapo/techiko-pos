<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\SaleImport;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
            ValidateCsrfToken::class,
        ]);
    }

    /** @return array{domain: Domain, location: InventoryLocation, user: User, piece: Product, kilo: Product} */
    private function seedContext(): array
    {
        $domain = Domain::query()->create([
            'name' => 'Test Org',
            'name_slug' => 'test-org-'.Str::lower(Str::random(6)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $location->id,
        ]);

        $category = Category::factory()->create(['domain' => $domain->name_slug]);

        $make = fn (array $attrs) => tap(Product::factory()->create(array_merge([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
            'track_inventory' => true,
        ], $attrs)), function (Product $p) use ($location) {
            $p->activeLocations()->attach($location->id, ['is_active' => true]);
            ProductInventory::query()->create([
                'product_id' => $p->id,
                'location_id' => $location->id,
                'quantity_on_hand' => 100,
                'quantity_reserved' => 0,
                'quantity_available' => 100,
            ]);
        });

        $piece = $make(['price' => 50, 'barcode' => 'BC-PIECE', 'SKU' => 'SKU-PIECE', 'sold_type' => 'piece']);
        $kilo = $make(['price' => 80, 'barcode' => 'BC-KILO', 'SKU' => 'SKU-KILO', 'sold_type' => 'kilo']);

        return compact('domain', 'location', 'user', 'piece', 'kilo');
    }

    private function csv(array $rows): UploadedFile
    {
        $header = 'receipt_no,date,product_barcode_or_sku,quantity,unit_price,payment_method,reference';
        $content = $header."\n".implode("\n", $rows)."\n";

        return UploadedFile::fake()->createWithContent('sales-'.Str::random(6).'.csv', $content);
    }

    private function preview(array $ctx, UploadedFile $file)
    {
        // Multipart (a real upload), not JSON.
        return $this->actingAs($ctx['user'])->post(
            route('domains.sales-import.preview', ['domain' => $ctx['domain']->name_slug]),
            ['file' => $file, 'location_id' => $ctx['location']->id],
            ['Accept' => 'application/json'],
        );
    }

    private function store(array $ctx, string $token, bool $deduct = false)
    {
        return $this->actingAs($ctx['user'])->postJson(
            route('domains.sales-import.store', ['domain' => $ctx['domain']->name_slug]),
            ['token' => $token, 'deduct_stock' => $deduct],
        );
    }

    public function test_template_downloads_with_header_and_examples(): void
    {
        $ctx = $this->seedContext();

        $response = $this->actingAs($ctx['user'])
            ->get(route('domains.sales-import.template', ['domain' => $ctx['domain']->name_slug]));

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('receipt_no,date,product_barcode_or_sku,quantity,unit_price,payment_method,reference', $body);
        $this->assertStringContainsString('R-1001', $body);
    }

    public function test_valid_file_is_imported_on_its_real_dates_without_touching_stock(): void
    {
        $ctx = $this->seedContext();
        $day = now()->subDays(20)->toDateString();
        $other = now()->subDays(10)->toDateString();

        $preview = $this->preview($ctx, $this->csv([
            "R1,{$day},BC-PIECE,2,45,cash,",
            "R1,{$day},SKU-KILO,1.5,,cash,",
            "R2,{$other},BC-PIECE,1,,e-wallet,GC-1",
        ]))->assertOk()
            ->assertJsonPath('sales_count', 2)
            ->assertJsonPath('errors_count', 0);

        $this->assertSame(0, Sale::query()->count(), 'Preview must not save anything.');

        $this->store($ctx, $preview->json('token'))->assertOk()->assertJsonPath('sales_count', 2);

        $r1 = Sale::query()->where('invoice_number', 'like', 'IMP-%-R1')->firstOrFail();
        $this->assertSame($day, substr((string) $r1->transaction_date, 0, 10));
        $this->assertSame('paid', $r1->payment_status);
        $this->assertNotNull($r1->import_id);
        $this->assertEquals(2 * 45 + 1.5 * 80, (float) $r1->grand_total);
        $this->assertCount(2, $r1->saleItems);

        $r2 = Sale::query()->where('invoice_number', 'like', 'IMP-%-R2')->firstOrFail();
        $this->assertSame($other, substr((string) $r2->transaction_date, 0, 10));
        $this->assertSame('e-wallet', $r2->payment_method);
        $this->assertSame('GC-1', $r2->payment_reference);
        $this->assertEquals(50, (float) $r2->grand_total, 'Empty unit_price uses the product price.');

        $this->assertEquals(100, ProductInventory::query()->where('product_id', $ctx['piece']->id)->value('quantity_on_hand'));
        $this->assertSame(0, InventoryMovement::query()->where('movement_type', 'sale')->count());
    }

    public function test_deduct_stock_option_takes_stock_out_dated_on_the_sale_day(): void
    {
        $ctx = $this->seedContext();
        $day = now()->subDays(5)->toDateString();

        $token = $this->preview($ctx, $this->csv(["R1,{$day},BC-PIECE,3,,cash,"]))->json('token');
        $this->store($ctx, $token, true)->assertOk();

        $this->assertEquals(97, ProductInventory::query()->where('product_id', $ctx['piece']->id)->value('quantity_on_hand'));
        $movement = InventoryMovement::query()->where('movement_type', 'sale')->firstOrFail();
        $this->assertSame($day, $movement->created_at->toDateString());
    }

    public function test_bad_rows_are_reported_and_their_receipts_are_skipped_whole(): void
    {
        $ctx = $this->seedContext();
        $day = now()->subDay()->toDateString();
        $future = now()->addDays(3)->toDateString();

        $response = $this->preview($ctx, $this->csv([
            "GOOD,{$day},BC-PIECE,1,,cash,",
            "BAD1,{$future},BC-PIECE,1,,cash,",
            "BAD2,{$day},NOPE,1,,cash,",
            "BAD3,{$day},BC-PIECE,1.5,,cash,",
            "BAD4,{$day},BC-PIECE,1,,barter,",
            "BAD5,{$day},BC-PIECE,1,,cash,",
            "BAD5,{$day},BC-PIECE,0,,cash,",
            "MIX,{$day},BC-PIECE,1,,cash,",
            "MIX,{$day},BC-PIECE,1,,card,",
        ]))->assertOk();

        $response->assertJsonPath('sales_count', 1);
        $messages = collect($response->json('errors'))->pluck('message')->implode(' | ');
        $this->assertStringContainsString('future', $messages);
        $this->assertStringContainsString('No product', $messages);
        $this->assertStringContainsString('whole number', $messages);
        $this->assertStringContainsString('payment_method', $messages);
        $this->assertStringContainsString('different payment methods', $messages);

        $this->store($ctx, $response->json('token'))->assertOk()->assertJsonPath('sales_count', 1);
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_the_same_file_cannot_be_imported_twice(): void
    {
        $ctx = $this->seedContext();
        $rows = ["R1,".now()->subDay()->toDateString().",BC-PIECE,1,,cash,"];
        $content = "receipt_no,date,product_barcode_or_sku,quantity,unit_price,payment_method,reference\n".implode("\n", $rows)."\n";

        $first = $this->preview($ctx, UploadedFile::fake()->createWithContent('a.csv', $content));
        $this->store($ctx, $first->json('token'))->assertOk();

        $this->preview($ctx, UploadedFile::fake()->createWithContent('b.csv', $content))
            ->assertStatus(422);

        $this->assertSame(1, SaleImport::query()->count());
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_a_file_with_the_wrong_columns_is_rejected(): void
    {
        $ctx = $this->seedContext();

        $this->preview($ctx, UploadedFile::fake()->createWithContent('x.csv', "foo,bar\n1,2\n"))
            ->assertStatus(422);
    }

    public function test_cashiers_cannot_import(): void
    {
        $ctx = $this->seedContext();
        $cashier = User::factory()->create([
            'domain' => $ctx['domain']->name_slug,
            'is_super_user' => false,
            'role_level' => 5,
            'location_id' => $ctx['location']->id,
        ]);

        $this->actingAs($cashier)
            ->get(route('domains.sales-import.template', ['domain' => $ctx['domain']->name_slug]))
            ->assertForbidden();
    }
}
