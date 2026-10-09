<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Finance\FinancialAccount;
use App\Models\Finance\FinancialSnapshot;
use App\Models\Finance\MonthlyReview;
use App\Models\Finance\OtherLiability;
use App\Models\Finance\OwnerInvestment;
use App\Models\InventoryLocation;
use App\Models\PaymentCardType;
use App\Models\Product\Product;
use App\Models\ProductInventory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Notifications\MonthlyReviewReady;
use App\Services\Ai\FinanceTools;
use App\Services\Finance\FinancialInsightService;
use App\Services\Finance\FinancialReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Other liabilities, accumulated profit, the daily snapshots that give inventory, customer
 * credit and cash a history, accounts fed by a payment channel, and the review email.
 */
class FinanceHistoryTest extends TestCase
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

    /** @return array{domain: Domain, location: InventoryLocation, user: User, product: Product} */
    private function seedDomainContext(): array
    {
        $domain = Domain::query()->create(['name' => 'History Shop', 'name_slug' => 'history-shop-'.Str::lower(Str::random(8))]);
        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug, 'name' => 'Store A', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $user = User::factory()->create([
            'domain' => $domain->name_slug, 'is_super_user' => true, 'location_id' => $location->id, 'email_verified_at' => now(),
        ]);
        $category = Category::factory()->create(['domain' => $domain->name_slug]);
        $product = Product::factory()->create(['domain' => $domain->name_slug, 'category_id' => $category->id, 'cost' => 30]);

        return compact('domain', 'location', 'user', 'product');
    }

    private function url(array $ctx, string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $ctx['domain']->name_slug] + $params);
    }

    /** A paid sale of `$quantity` × ₱40 (cost ₱30 each), no VAT. */
    private function sell(array $ctx, int $quantity, array $overrides = []): Sale
    {
        $total = 40 * $quantity;
        $sale = Sale::query()->create(array_merge([
            'domain' => $ctx['domain']->name_slug, 'user_id' => $ctx['user']->id, 'location_id' => $ctx['location']->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)), 'payment_method' => 'cash', 'payment_status' => 'paid',
            'total_amount' => $total, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $total,
            'transaction_date' => now()->startOfDay()->addHour(),
        ], $overrides));
        SaleItem::query()->create(['sale_id' => $sale->id, 'product_id' => $ctx['product']->id, 'quantity' => $quantity, 'unit_price' => 40]);

        return $sale;
    }

    private function snapshot(array $ctx, string $date, array $figures): FinancialSnapshot
    {
        return FinancialSnapshot::query()->create(['domain' => $ctx['domain']->name_slug, 'as_of_date' => $date, ...$figures]);
    }

    private function stock(array $ctx, float $value): void
    {
        ProductInventory::query()->updateOrCreate(
            ['product_id' => $ctx['product']->id, 'location_id' => $ctx['location']->id],
            ['quantity_on_hand' => 100, 'quantity_available' => 100, 'average_cost' => $value / 100, 'total_value' => $value],
        );
    }

    public function test_other_liabilities_count_until_settled(): void
    {
        $ctx = $this->seedDomainContext();

        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.other-liabilities.store'), [
                'name' => 'VAT for September', 'category' => 'tax', 'amount' => 4200, 'incurred_date' => today()->subDays(5)->toDateString(),
            ])->assertSessionHasNoErrors();
        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.other-liabilities.store'), [
                'name' => 'Catering deposit', 'category' => 'customer_deposit', 'amount' => 3000, 'incurred_date' => today()->toDateString(),
            ])->assertSessionHasNoErrors();

        $deposit = OtherLiability::query()->where('name', 'Catering deposit')->sole();
        $this->actingAs($ctx['user'])
            ->put($this->url($ctx, 'finance.other-liabilities.update', ['liability' => $deposit->id]), [
                'name' => 'Catering deposit', 'category' => 'customer_deposit', 'amount' => 3000,
                'incurred_date' => today()->toDateString(), 'settled_date' => today()->toDateString(),
            ])->assertSessionHasNoErrors();

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.balance-sheet'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('balanceSheet.liabilities.2.key', 'other_liabilities')
                ->where('balanceSheet.liabilities.2.amount', 4200)
                ->has('balanceSheet.other_liabilities', 1)
                ->where('balanceSheet.net_worth', -4200)
            );

        $this->assertEquals(4200, FinancialSnapshot::query()->sole()->other_liabilities);
    }

    public function test_equity_shows_accumulated_profit_and_what_records_dont_explain(): void
    {
        $ctx = $this->seedDomainContext();
        $this->sell($ctx, 10, ['transaction_date' => now()->subDays(40)]); // gross profit 100
        $this->sell($ctx, 20);                                               // gross profit 200
        OwnerInvestment::query()->create([
            'domain' => $ctx['domain']->name_slug, 'investment_date' => today(), 'amount' => 5000,
            'payment_method' => 'bank', 'user_id' => $ctx['user']->id,
        ]);
        $this->stock($ctx, 8000);

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.balance-sheet'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('balanceSheet.net_worth', 8000)
                ->where('balanceSheet.equity.0.amount', 5000)
                ->where('balanceSheet.equity.1.key', 'accumulated_profit')
                ->where('balanceSheet.equity.1.amount', 300)
                ->where('balanceSheet.equity.2.amount', 2700)
                ->where('balanceSheet.profit_since', now()->subDays(40)->toDateString())
            );
    }

    public function test_snapshots_give_inventory_credit_and_cash_a_history(): void
    {
        $ctx = $this->seedDomainContext();
        $slug = $ctx['domain']->name_slug;
        $this->stock($ctx, 12000);
        Customer::query()->create([
            'domain' => $slug, 'name' => 'Owing Customer', 'email' => 'owing-'.Str::random(5).'@example.test',
            'credit_enabled' => true, 'credit_limit' => 50000, 'credit_balance' => 3000, 'credit_terms_days' => 30,
        ]);
        $this->sell($ctx, 5);

        // How things stood at the end of the previous period ("this month so far" is compared
        // with the same days last month, so that ends on this day last month).
        $previousEnd = now()->subMonthNoOverflow()->toDateString();
        $this->snapshot($ctx, $previousEnd, ['inventory_value' => 10000, 'receivables' => 2000, 'cash_in_drawer' => 0, 'accounts_balance' => 400]);

        // Opening the Finance overview takes today's snapshot.
        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.dashboard', ['period' => 'month']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview.position.as_of', $previousEnd)
                ->where('overview.position.changes.inventory_value.pct', 20)
                ->where('overview.position.changes.receivables.pct', 50)
                ->where('health', fn ($health) => collect($health)->firstWhere('key', 'cash')['label'] === 'Cash'
                    && collect($health)->firstWhere('key', 'credit')['status'] === 'Increasing'
                    && str_contains(collect($health)->firstWhere('key', 'inventory')['detail'], 'up 20% from ₱10,000.00'))
            );
        $this->assertTrue(FinancialSnapshot::query()->whereDate('as_of_date', today())->exists());

        $this->artisan('finance:snapshot', ['--domain' => $slug])->assertSuccessful();
        $this->assertEquals(12000, FinancialSnapshot::query()->whereDate('as_of_date', today())->sole()->inventory_value);

        // The assistant can read the month-end history.
        $tools = new FinanceTools(app(FinancialReportService::class), app(FinancialInsightService::class), $slug, null);
        $history = json_decode($tools->run('get_balance_history', ['months' => 3]), true);
        $this->assertSame([now()->subMonthNoOverflow()->format('Y-m'), now()->format('Y-m')], array_column($history['month_ends'], 'month'));
        $this->assertEquals(10000, $history['month_ends'][0]['inventory_value']);
    }

    public function test_without_history_cash_health_falls_back_to_money_received(): void
    {
        $ctx = $this->seedDomainContext();
        $this->sell($ctx, 5);

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.dashboard', ['period' => 'month']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview.position', null)
                ->where('health', fn ($health) => collect($health)->firstWhere('key', 'cash')['label'] === 'Money received')
            );
    }

    public function test_an_account_fed_by_a_payment_channel_adds_sales_received_since_its_balance(): void
    {
        $ctx = $this->seedDomainContext();
        $gcash = PaymentCardType::query()->create([
            'domain' => $ctx['domain']->name_slug, 'location_id' => $ctx['location']->id, 'name' => 'GCash', 'kind' => 'ewallet', 'is_active' => true,
        ]);

        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.accounts.store'), [
                'name' => 'GCash wallet', 'type' => 'ewallet', 'payment_card_type_id' => $gcash->id,
                'balance' => 1000, 'as_of_date' => today()->subDay()->toDateString(),
            ])->assertSessionHasNoErrors();

        // Paid through GCash before and after the balance was entered; only after counts.
        $this->sell($ctx, 5, ['payment_method' => 'e-wallet', 'payment_card_type_id' => $gcash->id, 'transaction_date' => now()->subDays(2)]);
        $this->sell($ctx, 10, ['payment_method' => 'e-wallet', 'payment_card_type_id' => $gcash->id]);
        $this->sell($ctx, 3); // cash: not this account

        $account = FinancialAccount::query()->sole();
        $this->assertEquals(1400, $account->balanceOn(today()->toDateString()));

        $this->actingAs($ctx['user'])
            ->get($this->url($ctx, 'finance.balance-sheet'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('balanceSheet.assets.2.key', 'ewallet')
                ->where('balanceSheet.assets.2.amount', 1400)
                ->where('balanceSheet.accounts.0.received_since', 400)
                ->where('balanceSheet.accounts.0.channel', 'GCash')
            );

        // A newer balance resets the starting point.
        $this->actingAs($ctx['user'])
            ->post($this->url($ctx, 'finance.account-balances.store', ['account' => $account->id]), [
                'as_of_date' => today()->toDateString(), 'balance' => 1500,
            ])->assertSessionHasNoErrors();
        $this->assertEquals(1500, $account->fresh()->balanceOn(today()->toDateString()));
    }

    public function test_the_scheduled_review_uses_month_end_balances_and_emails_owners(): void
    {
        Notification::fake();
        $ctx = $this->seedDomainContext();
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
        $this->sell($ctx, 10, ['transaction_date' => $lastMonth->copy()->subMonthNoOverflow()->addDays(2)]);
        $this->sell($ctx, 10, ['transaction_date' => $lastMonth->copy()->addDays(2)]);

        $this->snapshot($ctx, $lastMonth->copy()->subDay()->toDateString(), ['inventory_value' => 10000, 'receivables' => 1000, 'cash_in_drawer' => 5000]);
        $this->snapshot($ctx, $lastMonth->copy()->endOfMonth()->toDateString(), ['inventory_value' => 13000, 'receivables' => 1500, 'cash_in_drawer' => 4000]);

        $this->artisan('finance:monthly-review', ['--domain' => $ctx['domain']->name_slug])->assertSuccessful();

        $review = MonthlyReview::query()->sole();
        $this->assertContains('Inventory value rose 30% to ₱13,000.00: more money is sitting on the shelf.', $review->needs_attention);
        $this->assertContains('Customers owe more: ₱1,500.00 at month end, up 50%.', $review->needs_attention);
        $this->assertContains('Cash on hand fell 20% to ₱4,000.00.', $review->needs_attention);

        Notification::assertSentTo($ctx['user'], MonthlyReviewReady::class, function (MonthlyReviewReady $n) use ($review, $ctx) {
            $mail = $n->toMail($ctx['user']);

            return $n->review->is($review) && str_contains($mail->subject, $review->label);
        });
    }

    public function test_reviews_can_be_written_without_emailing(): void
    {
        Notification::fake();
        $ctx = $this->seedDomainContext();
        $this->sell($ctx, 10, ['transaction_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2)]);

        $this->artisan('finance:monthly-review', ['--domain' => $ctx['domain']->name_slug, '--no-email' => true])->assertSuccessful();

        $this->assertSame(1, MonthlyReview::query()->count());
        Notification::assertNothingSent();
    }
}
