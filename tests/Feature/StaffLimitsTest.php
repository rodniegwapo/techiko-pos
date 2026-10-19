<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserPin;
use App\Models\WalletCashReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What cashiers can't do alone, and what supervisors and up can: back-dated sales, points
 * adjustments (a manager's PIN), and the warehouse (stores only).
 */
class StaffLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);

        $this->domain = Domain::query()->create(['name' => 'Limits Org', 'name_slug' => 'limits-org-'.Str::lower(Str::random(6))]);
        $location = fn (string $name, string $type, bool $default) => InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => $type, 'is_active' => true, 'is_default' => $default,
        ]);
        $this->store = $location('Main Store', 'store', true);
        $this->warehouse = $location('Warehouse', 'warehouse', false);
    }

    private function staff(string $role, int $level, ?InventoryLocation $at = null): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $r->forceFill(['level' => $level])->save();
        $user = User::factory()->create([
            'domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => ($at ?? $this->store)->id,
        ]);
        $user->assignRole($r);

        return $user;
    }

    private function pendingSale(User $user): Sale
    {
        $category = Category::factory()->create(['domain' => $this->domain->name_slug]);
        $product = Product::factory()->create([
            'domain' => $this->domain->name_slug, 'category_id' => $category->id, 'track_inventory' => true, 'price' => 100,
        ]);
        $product->activeLocations()->attach($this->store->id, ['is_active' => true]);
        ProductInventory::query()->create([
            'product_id' => $product->id, 'location_id' => $this->store->id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0, 'quantity_available' => 10,
        ]);

        $sale = Sale::query()->create([
            'user_id' => $user->id, 'location_id' => $this->store->id, 'domain' => $this->domain->name_slug,
            'payment_status' => 'pending', 'invoice_number' => Str::upper(Str::random(10)), 'transaction_date' => now(),
            'grand_total' => 0, 'total_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'payment_method' => 'cash',
        ]);
        $sale->saleItems()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]);
        $sale->recalcTotals();

        return $sale;
    }

    private function pay(User $user, Sale $sale, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('domains.sales.payment.store', [
            'domain' => $this->domain->name_slug, 'sale' => $sale->id,
        ]), ['payment_method' => 'cash', ...$extra]);
    }

    public function test_a_supervisor_can_record_a_sale_on_an_earlier_day(): void
    {
        $supervisor = $this->staff('supervisor', 4);
        $sale = $this->pendingSale($supervisor);
        $day = now()->subDays(3)->toDateString();

        // No shift was ever opened that day; it only must not be closed.
        $this->pay($supervisor, $sale, ['transaction_date' => $day])->assertOk();

        $this->assertSame($day, \Carbon\Carbon::parse($sale->fresh()->transaction_date)->toDateString());
        $this->assertSame('paid', $sale->fresh()->payment_status);
    }

    public function test_a_closed_day_or_a_cashier_cannot_take_a_back_dated_sale(): void
    {
        $supervisor = $this->staff('supervisor', 4);
        $day = now()->subDays(2)->toDateString();
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id,
            'business_date' => $day, 'opening_cash' => 0, 'is_closed' => true,
        ]);
        $this->pay($supervisor, $this->pendingSale($supervisor), ['transaction_date' => $day])
            ->assertStatus(422)->assertJsonValidationErrors('transaction_date');

        $cashier = $this->staff('cashier', 5);
        $sale = $this->pendingSale($cashier);
        $this->pay($cashier, $sale, ['transaction_date' => now()->subDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('transaction_date');
        $this->assertSame('pending', $sale->fresh()->payment_status);
    }

    public function test_a_cashier_adjusts_points_only_with_a_managers_pin(): void
    {
        $cashier = $this->staff('cashier', 5);
        $manager = $this->staff('manager', 3);
        UserPin::query()->forceCreate(['user_id' => $manager->id, 'pin_code' => Hash::make('4321')]);
        $customer = Customer::query()->create(['domain' => $this->domain->name_slug, 'name' => 'Juan', 'loyalty_points' => 100]);
        $url = route('domains.loyalty.adjust-points', ['domain' => $this->domain->name_slug, 'customer' => $customer->id]);
        $body = ['type' => 'add', 'amount' => 50, 'reason' => 'Promo'];

        $this->actingAs($cashier)->postJson($url, $body)->assertStatus(422)->assertJsonValidationErrors('pin_code');
        $this->actingAs($cashier)->postJson($url, $body + ['pin_code' => '0000'])->assertStatus(422)->assertJsonValidationErrors('pin_code');
        $this->assertSame(100, (int) $customer->fresh()->loyalty_points);

        $this->actingAs($cashier)->postJson($url, $body + ['pin_code' => '4321'])->assertOk();
        $this->assertSame(150, (int) $customer->fresh()->loyalty_points);

        // A manager needs no one's PIN.
        $this->actingAs($manager)->postJson($url, $body)->assertOk();
        $this->assertSame(200, (int) $customer->fresh()->loyalty_points);
    }

    public function test_a_cashier_never_lands_in_a_warehouse(): void
    {
        $cashier = $this->staff('cashier', 5, $this->warehouse);

        $this->actingAs($cashier)
            ->get(route('domains.customers.index', ['domain' => $this->domain->name_slug, 'location_id' => $this->warehouse->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentLocation.id', $this->store->id)
                ->where('availableLocations', fn ($locations) => collect($locations)->pluck('id')->all() === [$this->store->id]));

        // A manager still works there (on a page a warehouse shows).
        $manager = $this->staff('manager', 3, $this->warehouse);
        $this->actingAs($manager)
            ->get(route('domains.credits.index', ['domain' => $this->domain->name_slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('currentLocation.id', $this->warehouse->id));
    }
}
