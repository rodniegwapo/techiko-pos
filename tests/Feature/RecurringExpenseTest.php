<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryLocation;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Models\WalletCashReconciliation;
use App\Services\RecurringExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecurringExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private ExpenseCategory $rent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);

        $this->domain = Domain::query()->create([
            'name' => 'Recurring Org',
            'name_slug' => 'recurring-org-'.Str::lower(Str::random(8)),
        ]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);
        ExpenseCategory::ensureDefaults($this->domain->name_slug);
        $this->rent = ExpenseCategory::query()->forDomain($this->domain->name_slug)->where('name', 'Rent')->firstOrFail();

        $role = Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->forceFill(['level' => 2])->save();
        $this->admin = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false]);
        $this->admin->assignRole($role);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function route(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug] + $params);
    }

    private function template(array $overrides = []): RecurringExpense
    {
        $template = new RecurringExpense(array_merge([
            'domain' => $this->domain->name_slug,
            'location_id' => $this->store->id,
            'expense_category_id' => $this->rent->id,
            'amount' => 10000,
            'description' => 'Shop rent',
            'payment_method' => 'bank',
            'frequency' => 'monthly',
            'day_of_month' => 5,
            'start_date' => '2026-01-01',
            'user_id' => $this->admin->id,
        ], $overrides));
        $template->next_run_date = $template->firstRunOnOrAfter($template->start_date);
        $template->save();

        return $template;
    }

    public function test_due_monthly_runs_are_booked_once(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $template = $this->template();
        $service = app(RecurringExpenseService::class);

        $this->assertSame(3, $service->generateDue($this->domain->name_slug));
        $this->assertSame(
            ['2026-01-05', '2026-02-05', '2026-03-05'],
            Expense::query()->orderBy('expense_date')->get()->map(fn ($e) => $e->expense_date->toDateString())->all()
        );
        $this->assertSame('2026-04-05', $template->fresh()->next_run_date->toDateString());

        // Running again books nothing new.
        $this->assertSame(0, $service->generateDue($this->domain->name_slug));
        $this->assertSame(3, Expense::query()->count());
    }

    public function test_day_31_falls_on_the_last_day_of_short_months(): void
    {
        Carbon::setTestNow('2026-04-30 09:00:00');
        $this->template(['day_of_month' => 31, 'start_date' => '2026-02-01']);

        app(RecurringExpenseService::class)->generateDue($this->domain->name_slug);

        $this->assertSame(
            ['2026-02-28', '2026-03-31', '2026-04-30'],
            Expense::query()->orderBy('expense_date')->get()->map(fn ($e) => $e->expense_date->toDateString())->all()
        );
    }

    public function test_weekly_runs_land_on_the_chosen_weekday(): void
    {
        Carbon::setTestNow('2026-10-17 09:00:00'); // Saturday
        $this->template(['frequency' => 'weekly', 'day_of_month' => null, 'day_of_week' => 1, 'start_date' => '2026-10-01']); // Mondays

        app(RecurringExpenseService::class)->generateDue($this->domain->name_slug);

        $this->assertSame(
            ['2026-10-05', '2026-10-12'],
            Expense::query()->orderBy('expense_date')->get()->map(fn ($e) => $e->expense_date->toDateString())->all()
        );
    }

    public function test_paused_templates_are_skipped_and_end_date_stops_the_schedule(): void
    {
        Carbon::setTestNow('2026-06-10 09:00:00');
        $this->template(['is_active' => false]);
        $ending = $this->template(['description' => 'Short lease', 'end_date' => '2026-03-31']);

        app(RecurringExpenseService::class)->generateDue($this->domain->name_slug);

        $this->assertSame(0, Expense::query()->where('description', 'Shop rent')->count());
        $this->assertSame(3, Expense::query()->where('description', 'Short lease')->count()); // Jan, Feb, Mar
        $this->assertFalse($ending->fresh()->is_active);
    }

    public function test_cash_run_on_a_closed_shift_is_booked_outside_the_wallet(): void
    {
        Carbon::setTestNow('2026-01-06 09:00:00');
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug,
            'location_id' => $this->store->id,
            'business_date' => '2026-01-05',
            'is_closed' => true,
        ]);
        $this->template(['payment_method' => 'cash_register']);

        app(RecurringExpenseService::class)->generateDue($this->domain->name_slug);

        $expense = Expense::query()->sole();
        $this->assertSame('other', $expense->payment_method);
        $this->assertNull($expense->wallet_cash_movement_id);
    }

    public function test_repeat_on_a_new_expense_creates_a_template_starting_next_month(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');

        $this->actingAs($this->admin)->post($this->route('expenses.store'), [
            'expense_category_id' => $this->rent->id,
            'amount' => 12000,
            'expense_date' => '2026-10-07',
            'description' => 'October rent',
            'payment_method' => 'bank',
            'location_id' => $this->store->id,
            'repeat' => 'monthly',
        ])->assertSessionHasNoErrors();

        $template = RecurringExpense::query()->sole();
        $this->assertSame(7, $template->day_of_month);
        $this->assertSame('2026-11-07', $template->next_run_date->toDateString());
        $this->assertSame($template->id, Expense::query()->sole()->recurring_expense_id);

        // A month later, opening Expenses books November's rent without any cron.
        Carbon::setTestNow('2026-11-08 09:00:00');
        $this->actingAs($this->admin)->get($this->route('expenses.index'))->assertOk();
        $this->assertSame(2, Expense::query()->count());
    }

    public function test_templates_can_be_created_paused_and_deleted(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');

        $this->actingAs($this->admin)->post($this->route('expenses.recurring.store'), [
            'expense_category_id' => $this->rent->id,
            'amount' => 500,
            'description' => 'Internet',
            'payment_method' => 'bank',
            'location_id' => null,
            'frequency' => 'monthly',
            'day_of_month' => 20,
            'start_date' => '2026-10-07',
        ])->assertSessionHasNoErrors();
        $template = RecurringExpense::query()->sole();
        $this->assertSame('2026-10-20', $template->next_run_date->toDateString());
        $this->assertSame(0, Expense::query()->count());

        $this->actingAs($this->admin)->put($this->route('expenses.recurring.update', ['recurring' => $template->id]), ['is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($template->fresh()->is_active);

        $this->actingAs($this->admin)->delete($this->route('expenses.recurring.destroy', ['recurring' => $template->id]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('recurring_expenses', ['id' => $template->id]);
    }
}
