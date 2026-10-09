<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Product\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Ai\FinancialAssistant;
use App\Services\Finance\FinancialReportService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceTest extends TestCase
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

    /**
     * @return array{domain: Domain, location: InventoryLocation, user: User, product: Product}
     */
    private function seedDomainContext(string $name = 'Finance Org'): array
    {
        $domain = Domain::query()->create([
            'name' => $name,
            'name_slug' => 'finance-org-'.Str::lower(Str::random(8)),
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

        $category = Category::factory()->create(['domain' => $domain->name_slug]);
        $product = Product::factory()->create(['domain' => $domain->name_slug, 'category_id' => $category->id, 'cost' => 30]);

        return compact('domain', 'location', 'user', 'product');
    }

    /** A paid sale of `$quantity` × ₱40 (cost ₱30 each), VAT 12% on top. */
    private function sellProduct(array $ctx, int $quantity, array $overrides = []): Sale
    {
        $net = 40 * $quantity;
        $sale = Sale::query()->create(array_merge([
            'domain' => $ctx['domain']->name_slug,
            'user_id' => $ctx['user']->id,
            'location_id' => $ctx['location']->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'total_amount' => $net,
            'discount_amount' => 0,
            'tax_amount' => $net * 0.12,
            'grand_total' => $net * 1.12,
            'transaction_date' => now()->setTime(10, 0),
        ], $overrides));

        SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $ctx['product']->id, 'quantity' => $quantity, 'unit_price' => 40]);

        return $sale;
    }

    private function makeCustomer(array $ctx, string $name, float $balance): Customer
    {
        return Customer::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'name' => $name,
            'email' => Str::slug($name).'-'.Str::lower(Str::random(6)).'@example.test',
            'credit_enabled' => true,
            'credit_limit' => 10000,
            'credit_balance' => $balance,
            'credit_terms_days' => 30,
        ]);
    }

    private function charge(array $ctx, Customer $customer, float $amount, string $dueDate): CreditTransaction
    {
        return CreditTransaction::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $ctx['user']->id,
            'domain' => $ctx['domain']->name_slug,
            'transaction_type' => 'credit',
            'amount' => $amount,
            'paid_amount' => 0,
            'balance_before' => 0,
            'balance_after' => $amount,
            'due_date' => $dueDate,
        ]);
    }

    public function test_dashboard_computes_gross_profit_and_compares_with_the_previous_period(): void
    {
        $ctx = $this->seedDomainContext();
        $other = $this->seedDomainContext('Other Org');

        // Today: 2 × ₱40 → revenue 80, cost 60, gross profit 20.
        $this->sellProduct($ctx, 2);
        // Yesterday: 1 × ₱40 → revenue 40, cost 30, gross profit 10.
        $this->sellProduct($ctx, 1, ['transaction_date' => now()->subDay()->setTime(10, 0)]);
        // None of these count.
        $this->sellProduct($ctx, 5, ['payment_status' => 'voided']);
        $this->sellProduct($ctx, 5, ['payment_status' => 'pending']);
        $this->sellProduct($other, 5);

        $this->actingAs($ctx['user'])
            ->get(route('domains.finance.dashboard', ['domain' => $ctx['domain']->name_slug, 'period' => 'today']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Dashboard', false)
                ->where('overview.current.sales_count', 1)
                ->where('overview.current.total_paid', 89.6)
                ->where('overview.current.vat', 9.6)
                ->where('overview.current.revenue', 80)
                ->where('overview.current.cogs', 60)
                ->where('overview.current.gross_profit', 20)
                ->where('overview.current.gross_margin_pct', 25)
                ->where('overview.previous.revenue', 40)
                ->where('overview.changes.revenue.pct', 100)
                ->where('overview.changes.gross_profit.amount', 10)
                ->where('overview.money_in.cash', 89.6)
                ->where('aiEnabled', false)
                ->has('health', 7)
                ->has('trend', 6)
            );
    }

    public function test_location_filter_only_counts_that_locations_sales(): void
    {
        $ctx = $this->seedDomainContext();
        $second = InventoryLocation::query()->create([
            'domain' => $ctx['domain']->name_slug,
            'name' => 'Store B',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
        ]);

        $this->sellProduct($ctx, 1);
        $this->sellProduct($ctx, 3, ['location_id' => $second->id]);

        $this->actingAs($ctx['user'])
            ->get(route('domains.finance.dashboard', [
                'domain' => $ctx['domain']->name_slug,
                'period' => 'today',
                'location_id' => $second->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Dashboard', false)
                ->where('filters.location_id', $second->id)
                ->where('overview.current.revenue', 120)
                ->where('overview.current.cogs', 90)
            );
    }

    public function test_custom_period_is_compared_with_the_same_number_of_days_before_it(): void
    {
        $period = app(FinancialReportService::class)->resolvePeriod('custom', '2026-03-10', '2026-03-16');

        $this->assertSame('2026-03-03', $period['previous_start']->toDateString());
        $this->assertSame('2026-03-09', $period['previous_end']->toDateString());

        $month = app(FinancialReportService::class)->resolvePeriod('month');
        $this->assertSame(now()->startOfMonth()->subMonthNoOverflow()->toDateString(), $month['previous_start']->toDateString());
    }

    public function test_receivables_show_what_is_owed_overdue_and_how_late(): void
    {
        $ctx = $this->seedDomainContext();

        $ana = $this->makeCustomer($ctx, 'Ana Reyes', 1500);
        $ben = $this->makeCustomer($ctx, 'Ben Santos', 500);
        $this->makeCustomer($ctx, 'Paid Up', 0);

        $this->charge($ctx, $ana, 1000, today()->subDays(45)->toDateString());   // 31–60 days late
        $this->charge($ctx, $ana, 500, today()->addDays(3)->toDateString());     // due within 7 days
        $this->charge($ctx, $ben, 500, today()->subDays(10)->toDateString());    // 1–30 days late

        $this->actingAs($ctx['user'])
            ->get(route('domains.finance.receivables', ['domain' => $ctx['domain']->name_slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Receivables', false)
                ->where('receivables.outstanding', 2000)
                ->where('receivables.overdue', 1500)
                ->where('receivables.not_yet_due', 500)
                ->where('receivables.overdue_pct', 75)
                ->where('receivables.due_within_7_days', 500)
                ->where('receivables.customers_owing', 2)
                ->where('receivables.aging.1_30', 500)
                ->where('receivables.aging.31_60', 1000)
                ->where('receivables.overdue_customers.0.name', 'Ana Reyes')
                ->where('receivables.overdue_customers.0.days_overdue', 45)
                ->where('receivables.largest_balances.0.name', 'Ana Reyes')
                ->where('receivables.period.new_credit', 2000)
            );
    }

    public function test_explain_sends_figures_without_customer_names_to_the_assistant(): void
    {
        $ctx = $this->seedDomainContext();
        $this->sellProduct($ctx, 2);
        $customer = $this->makeCustomer($ctx, 'Secret Customer', 800);
        $this->charge($ctx, $customer, 800, today()->subDays(5)->toDateString());

        $captured = null;
        $this->mock(FinancialAssistant::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('explain')
                ->once()
                ->withArgs(function (string $topic, array $facts, string $language, ?string $metric) use (&$captured) {
                    $captured = compact('topic', 'facts', 'language', 'metric');

                    return true;
                })
                ->andReturn(['text' => 'Your sales went up.', 'cached' => false]);
        });

        $this->actingAs($ctx['user'])
            ->postJson(route('domains.finance.explain', ['domain' => $ctx['domain']->name_slug]), [
                'topic' => 'metric',
                'metric' => 'Gross profit',
                'language' => 'taglish',
                'period' => 'today',
            ])
            ->assertOk()
            ->assertJsonPath('text', 'Your sales went up.')
            ->assertJsonStructure(['text', 'cached', 'disclaimer']);

        $this->assertSame('metric', $captured['topic']);
        $this->assertSame('Gross profit', $captured['metric']);
        $this->assertSame('taglish', $captured['language']);
        $this->assertEquals(80, $captured['facts']['current']['revenue']);
        $this->assertEquals(800, $captured['facts']['receivables']['outstanding']);
        $this->assertStringNotContainsString('Secret Customer', json_encode($captured['facts']));
        $this->assertSame('Customer A', $captured['facts']['receivables']['largest_balances'][0]['customer']);
    }

    public function test_explain_reports_when_the_assistant_is_not_set_up(): void
    {
        config(['services.anthropic.key' => '']);
        $ctx = $this->seedDomainContext();

        $this->actingAs($ctx['user'])
            ->postJson(route('domains.finance.explain', ['domain' => $ctx['domain']->name_slug]), ['topic' => 'overview'])
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'not set up'));
    }

    public function test_explain_rejects_unknown_topics(): void
    {
        $ctx = $this->seedDomainContext();

        $this->actingAs($ctx['user'])
            ->postJson(route('domains.finance.explain', ['domain' => $ctx['domain']->name_slug]), ['topic' => 'tell me a joke'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('topic');
    }
}
