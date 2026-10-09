<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dataset (this month = October 2026, previous = September):
 *   Store A: paid sale net 100 (VAT 12) with 2 × ₱30 items + 1 voided item; pending sale ignored.
 *   Store B: paid sale net 50 (VAT 6) with 1 × ₱30 item.
 *   September, store A: paid sale net 200, 2 × ₱30 items.
 *   Store A losses: approved damaged write-off 2 × ₱30; an oversell correction (not a loss).
 *   Expenses: store A 40, store B 10, business-wide 5.
 */
class ProfitLossTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $storeA;

    private InventoryLocation $storeB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);
        Carbon::setTestNow('2026-10-15 12:00:00');

        $this->domain = Domain::query()->create([
            'name' => 'PnL Org',
            'name_slug' => 'pnl-org-'.Str::lower(Str::random(8)),
        ]);
        $this->storeA = $this->makeLocation('Store A', true);
        $this->storeB = $this->makeLocation('Store B');
        $this->admin = $this->makeUser('admin', 2);

        $this->seedDataset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeLocation(string $name, bool $default = false): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug,
            'name' => $name,
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => $default,
        ]);
    }

    private function makeUser(string $roleName, int $level, ?InventoryLocation $location = null): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $role->forceFill(['level' => $level])->save();
        $user = User::factory()->create([
            'domain' => $this->domain->name_slug,
            'is_super_user' => false,
            'location_id' => $location?->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function sale(InventoryLocation $store, float $net, float $vat, string $date, string $status = 'paid'): Sale
    {
        return Sale::query()->create([
            'domain' => $this->domain->name_slug,
            'user_id' => $this->admin->id,
            'location_id' => $store->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'payment_method' => 'cash',
            'payment_status' => $status,
            'total_amount' => $net,
            'discount_amount' => 0,
            'tax_amount' => $vat,
            'grand_total' => $net + $vat,
            'transaction_date' => $date,
        ]);
    }

    private function writeOff(InventoryLocation $store, Product $product, string $reason, int $quantity): void
    {
        $id = DB::table('stock_adjustments')->insertGetId([
            'domain' => $this->domain->name_slug,
            'adjustment_number' => 'ADJ-'.Str::upper(Str::random(6)),
            'location_id' => $store->id,
            'type' => 'decrease',
            'reason' => $reason,
            'total_value_change' => -$quantity * 30,
            'status' => 'approved',
            'approved_at' => '2026-10-12 10:00:00',
            'created_by' => $this->admin->id,
            'approved_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_adjustment_items')->insert([
            'stock_adjustment_id' => $id,
            'product_id' => $product->id,
            'system_quantity' => 10,
            'actual_quantity' => 10 - $quantity,
            'adjustment_quantity' => -$quantity,
            'unit_cost' => 30,
            'total_cost_change' => -$quantity * 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedDataset(): void
    {
        $category = Category::factory()->create(['domain' => $this->domain->name_slug]);
        $product = Product::factory()->create(['domain' => $this->domain->name_slug, 'category_id' => $category->id, 'cost' => 30]);
        $line = fn (Sale $sale, int $qty) => SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'quantity' => $qty, 'unit_price' => 50]);

        $a = $this->sale($this->storeA, 100, 12, '2026-10-10 10:00:00');
        $line($a, 2);
        $line($a, 1)->delete(); // voided
        $line($this->sale($this->storeA, 999, 0, '2026-10-10 11:00:00', 'pending'), 5);
        $line($this->sale($this->storeB, 50, 6, '2026-10-11 10:00:00'), 1);
        $line($this->sale($this->storeA, 200, 24, '2026-09-10 10:00:00'), 2);

        $this->writeOff($this->storeA, $product, 'damaged_goods', 2);
        $this->writeOff($this->storeA, $product, 'oversell_found', 1);

        ExpenseCategory::ensureDefaults($this->domain->name_slug);
        $rent = ExpenseCategory::query()->forDomain($this->domain->name_slug)->where('name', 'Rent')->firstOrFail();
        foreach ([[$this->storeA->id, 40], [$this->storeB->id, 10], [null, 5]] as [$locationId, $amount]) {
            Expense::query()->create([
                'domain' => $this->domain->name_slug,
                'location_id' => $locationId,
                'expense_category_id' => $rent->id,
                'amount' => $amount,
                'expense_date' => '2026-10-05',
                'description' => 'Rent',
                'payment_method' => 'bank',
                'user_id' => $this->admin->id,
            ]);
        }
    }

    private function route(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug] + $params);
    }

    public function test_whole_business_statement_for_this_month(): void
    {
        $this->actingAs($this->admin)
            ->get($this->route('profit-loss.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/ProfitLoss', false)
                ->where('periods.current', 'October 2026')
                ->where('periods.previous', 'September 2026')
                ->where('current.net_sales', 150)
                ->where('current.cogs', 90)
                ->where('current.inventory_losses', 60)
                ->where('current.inventory_loss_lines.0.label', 'Damaged goods')
                ->where('current.gross_profit', 0)
                ->where('current.expenses', 55)
                ->where('current.net_profit', -55)
                ->where('current.memo.vat_collected', 18)
                ->where('current.memo.sales_count', 2)
                ->where('current.memo.business_wide_expenses_excluded', null)
                ->where('previous.net_sales', 200)
                ->where('previous.gross_profit', 140)
                ->where('previous.net_profit', 140)
                ->where('rows.7.key', 'net_profit')
                ->where('rows.7.change_percent', -139.3)
            );
    }

    public function test_store_filter_keeps_business_wide_expenses_out_but_reports_them(): void
    {
        $this->actingAs($this->admin)
            ->get($this->route('profit-loss.index', ['location_id' => $this->storeA->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('current.net_sales', 100)
                ->where('current.cogs', 60)
                ->where('current.inventory_losses', 60)
                ->where('current.gross_profit', -20)
                ->where('current.expenses', 40)
                ->where('current.net_profit', -60)
                ->where('current.memo.business_wide_expenses_excluded', 5)
            );
    }

    public function test_custom_range_compares_with_the_same_number_of_days_before(): void
    {
        $this->actingAs($this->admin)
            ->get($this->route('profit-loss.index', ['preset' => 'custom', 'start_date' => '2026-10-10', 'end_date' => '2026-10-11']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('periods.previous', 'Oct 8, 2026 – Oct 9, 2026')
                ->where('current.net_sales', 150)
                ->where('current.expenses', 0)
            );
    }

    public function test_manager_only_sees_their_own_store(): void
    {
        $manager = $this->makeUser('manager', 3, $this->storeB);

        $this->actingAs($manager)
            ->get($this->route('profit-loss.index', ['location_id' => $this->storeA->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.location_id', $this->storeB->id)
                ->where('canSeeAllStores', false)
                ->where('current.net_sales', 50)
                ->where('current.cogs', 30)
                ->where('current.inventory_losses', 0)
                ->where('current.expenses', 10)
                ->where('current.net_profit', 10)
            );
    }

    public function test_cashier_cannot_see_the_statement(): void
    {
        $cashier = $this->makeUser('cashier', 5, $this->storeA);

        $this->actingAs($cashier)->get($this->route('profit-loss.index'))->assertForbidden();
        $this->actingAs($cashier)->get($this->route('profit-loss.export'))->assertForbidden();
    }

    public function test_export_has_both_periods(): void
    {
        $response = $this->actingAs($this->admin)->get($this->route('profit-loss.export'));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Line,"October 2026","September 2026","Change %"', $csv);
        $this->assertStringContainsString('"Net sales",150.00,200.00,-25', $csv);
        $this->assertStringContainsString('"Net profit (before income tax)",-55.00,140.00,-139.3', $csv);
    }
}
