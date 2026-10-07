<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesVatSettingTest extends TestCase
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
     * @return array{domain: Domain, user: User, product: Product}
     */
    private function seedVatDomain(): array
    {
        $domain = Domain::query()->create([
            'name' => 'VAT Org',
            'name_slug' => 'vat-org-'.Str::lower(Str::random(8)),
            'settings' => ['sales' => [
                'apply_vat_automatically' => true,
                'vat_rate_percent' => 12,
                'vat_pricing_mode' => 'exclusive',
            ]],
        ]);
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => true]);
        $category = Category::factory()->create(['domain' => $domain->name_slug]);
        $product = Product::factory()->create(['domain' => $domain->name_slug, 'category_id' => $category->id]);

        return compact('domain', 'user', 'product');
    }

    private function makeSaleWithItem(Domain $domain, User $user, Product $product, string $status): Sale
    {
        $sale = Sale::query()->create([
            'domain' => $domain->name_slug,
            'user_id' => $user->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'payment_status' => $status,
            'total_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 0,
            'transaction_date' => now(),
        ]);
        SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]);
        $sale->recalcTotals();

        return $sale->fresh();
    }

    public function test_turning_vat_off_retotals_open_carts_but_not_completed_sales(): void
    {
        ['domain' => $domain, 'user' => $user, 'product' => $product] = $this->seedVatDomain();
        $cart = $this->makeSaleWithItem($domain, $user, $product, 'pending');
        $paid = $this->makeSaleWithItem($domain, $user, $product, 'paid');

        $this->assertEquals(12, (float) $cart->tax_amount);
        $this->assertEquals(112, (float) $cart->grand_total);

        $this->actingAs($user)
            ->patch(route('domains.settings.update', ['domain' => $domain->name_slug]), ['apply_vat_automatically' => false])
            ->assertRedirect();

        $cart->refresh();
        $this->assertEquals(0, (float) $cart->tax_amount);
        $this->assertEquals(100, (float) $cart->grand_total);

        $paid->refresh();
        $this->assertEquals(12, (float) $paid->tax_amount);
        $this->assertEquals(112, (float) $paid->grand_total);
    }

    public function test_switching_to_inclusive_pricing_retotals_open_carts(): void
    {
        ['domain' => $domain, 'user' => $user, 'product' => $product] = $this->seedVatDomain();
        $cart = $this->makeSaleWithItem($domain, $user, $product, 'pending');

        $this->actingAs($user)
            ->patch(route('domains.settings.update', ['domain' => $domain->name_slug]), ['vat_pricing_mode' => 'inclusive'])
            ->assertRedirect();

        $cart->refresh();
        $this->assertEquals(10.71, (float) $cart->tax_amount);
        $this->assertEquals(100, (float) $cart->grand_total);
    }
}
