<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "No info on Today's sale": a cart opened on an earlier day but paid today is today's sale,
 * the same way Sales History counts it (by when it was paid, not when the cart was opened).
 */
class DashboardTodaySalesTest extends TestCase
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

    public function test_a_sale_paid_today_counts_even_if_its_cart_was_opened_earlier(): void
    {
        $domain = Domain::query()->create([
            'name' => 'Dash Org',
            'name_slug' => 'dash-org-'.Str::lower(Str::random(8)),
        ]);
        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => 'DM-'.Str::upper(Str::random(6)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);
        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => true,
            'location_id' => $location->id,
        ]);

        $sale = fn (float $total, $openedAt, $paidAt) => tap(Sale::query()->create([
            'domain' => $domain->name_slug,
            'user_id' => $user->id,
            'location_id' => $location->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'total_amount' => $total,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => $total,
            'transaction_date' => $paidAt,
        ]), fn (Sale $s) => DB::table('sales')->where('id', $s->id)->update(['created_at' => $openedAt]));

        $sale(4400, now()->subDays(3), now());          // long-open cart, paid today
        $sale(350, now(), now());                       // walk-in, today
        $sale(999, now()->subDays(3), now()->subDays(3)); // paid days ago: not today

        $this->actingAs($user)
            ->get(route('domains.dashboard', ['domain' => $domain->name_slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.kpis.today_sales.value', fn ($v) => (float) $v === 4750.0));
    }
}
