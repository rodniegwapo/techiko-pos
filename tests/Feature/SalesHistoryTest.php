<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\VoidLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);
    }

    /**
     * @return array{domain: Domain, location: InventoryLocation, user: User}
     */
    private function seedDomainContext(string $name = 'History Org'): array
    {
        $domain = Domain::query()->create([
            'name' => $name,
            'name_slug' => 'history-org-'.Str::lower(Str::random(8)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Store A',
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

        return compact('domain', 'location', 'user');
    }

    private function makeSale(Domain $domain, User $user, array $overrides = []): Sale
    {
        return Sale::query()->create(array_merge([
            'domain' => $domain->name_slug,
            'user_id' => $user->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'total_amount' => 100.00,
            'discount_amount' => 0,
            'tax_amount' => 12.00,
            'grand_total' => 112.00,
            'transaction_date' => now()->setTime(10, 0),
        ], $overrides));
    }

    private function makeCashier(Domain $domain, InventoryLocation $location): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
        $role->forceFill(['level' => 5])->save();

        $cashier = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'location_id' => $location->id,
        ]);
        $cashier->assignRole($role);

        return $cashier;
    }

    public function test_list_shows_only_completed_sales_of_this_domain_for_the_date_range(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedDomainContext();
        ['domain' => $other, 'user' => $otherUser] = $this->seedDomainContext('Other Org');

        $mine = $this->makeSale($domain, $user, ['invoice_number' => 'INV-MINE']);
        $this->makeSale($domain, $user, ['invoice_number' => 'INV-CART', 'payment_status' => 'pending']);
        $this->makeSale($domain, $user, ['invoice_number' => 'INV-OLD', 'transaction_date' => now()->subDays(10)]);
        $this->makeSale($other, $otherUser, ['invoice_number' => 'INV-OTHER']);

        $this->actingAs($user)
            ->get(route('domains.sales-history.index', ['domain' => $domain->name_slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SalesHistory/Index', false)
                ->has('items.data', 1)
                ->where('items.data.0.id', $mine->id)
                ->where('summary.sales_count', 1)
                ->where('summary.net', 112)
                ->where('restrictedToOwnSales', false)
            );
    }

    public function test_filters_narrow_the_list(): void
    {
        ['domain' => $domain, 'user' => $user, 'location' => $location] = $this->seedDomainContext();
        $customer = Customer::query()->create(['domain' => $domain->name_slug, 'name' => 'Juana Cruz']);

        $this->makeSale($domain, $user, ['invoice_number' => 'INV-CASH']);
        $card = $this->makeSale($domain, $user, ['invoice_number' => 'INV-CARD', 'payment_method' => 'card', 'location_id' => $location->id]);
        $refunded = $this->makeSale($domain, $user, ['invoice_number' => 'INV-REF', 'payment_status' => 'refunded', 'customer_id' => $customer->id]);

        $url = fn (array $q) => route('domains.sales-history.index', ['domain' => $domain->name_slug] + $q);

        $this->actingAs($user)->get($url(['payment_method' => 'card']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $card->id));

        $this->actingAs($user)->get($url(['location_id' => $location->id]))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $card->id));

        $this->actingAs($user)->get($url(['payment_status' => 'refunded']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $refunded->id));

        $this->actingAs($user)->get($url(['search' => 'juana']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $refunded->id));

        $this->actingAs($user)->get($url(['search' => 'INV-CA']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 2));

        $this->actingAs($user)->get($url([
            'start_date' => now()->subDays(1)->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
        ]))->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
    }

    public function test_cashier_only_sees_their_own_sales(): void
    {
        ['domain' => $domain, 'user' => $manager, 'location' => $location] = $this->seedDomainContext();
        $cashier = $this->makeCashier($domain, $location);

        $own = $this->makeSale($domain, $cashier);
        $someoneElses = $this->makeSale($domain, $manager);

        // Asking for another user's sales is ignored.
        $this->actingAs($cashier)
            ->get(route('domains.sales-history.index', ['domain' => $domain->name_slug, 'user_id' => $manager->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $own->id)
                ->where('restrictedToOwnSales', true)
                ->where('options.cashiers', [])
            );

        $this->actingAs($cashier)
            ->getJson(route('domains.sales-history.show', ['domain' => $domain->name_slug, 'sale' => $someoneElses->id]))
            ->assertNotFound();
    }

    public function test_show_includes_voided_items_and_void_logs(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedDomainContext();
        $category = Category::factory()->create(['domain' => $domain->name_slug]);
        $product = Product::factory()->create(['domain' => $domain->name_slug, 'category_id' => $category->id, 'name' => 'Iced Coffee']);

        $sale = $this->makeSale($domain, $user);
        SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50]);
        $voided = SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]);
        VoidLog::query()->create([
            'sale_item_id' => $voided->id,
            'user_id' => $user->id,
            'approver_id' => $user->id,
            'reason' => 'Customer changed mind',
            'amount' => 50,
        ]);
        $voided->delete();

        $this->actingAs($user)
            ->getJson(route('domains.sales-history.show', ['domain' => $domain->name_slug, 'sale' => $sale->id]))
            ->assertOk()
            ->assertJsonPath('invoice_number', $sale->invoice_number)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.voided', false)
            ->assertJsonPath('items.1.voided', true)
            ->assertJsonPath('items.1.product_name', 'Iced Coffee')
            ->assertJsonCount(1, 'void_logs')
            ->assertJsonPath('void_logs.0.reason', 'Customer changed mind');

        $this->actingAs($user)
            ->get(route('domains.sales-history.index', ['domain' => $domain->name_slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.items_count', 1)
                ->where('items.data.0.voided_items_count', 1)
                ->where('summary.sales_with_voids', 1)
            );
    }

    public function test_show_is_404_for_another_domains_sale(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedDomainContext();
        ['domain' => $other, 'user' => $otherUser] = $this->seedDomainContext('Other Org');
        $foreign = $this->makeSale($other, $otherUser);

        $this->actingAs($user)
            ->getJson(route('domains.sales-history.show', ['domain' => $domain->name_slug, 'sale' => $foreign->id]))
            ->assertNotFound();
    }

    public function test_export_streams_csv_for_the_filtered_sales(): void
    {
        ['domain' => $domain, 'user' => $user] = $this->seedDomainContext();
        $this->makeSale($domain, $user, ['invoice_number' => 'INV-CSV-CASH']);
        $this->makeSale($domain, $user, ['invoice_number' => 'INV-CSV-CARD', 'payment_method' => 'card']);

        $response = $this->actingAs($user)
            ->get(route('domains.sales-history.export', ['domain' => $domain->name_slug, 'payment_method' => 'card']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('invoice_number,transaction_date,cashier', $csv);
        $this->assertStringContainsString('INV-CSV-CARD', $csv);
        $this->assertStringNotContainsString('INV-CSV-CASH', $csv);
    }
}
