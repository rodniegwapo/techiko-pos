<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\WalletCashMovement;
use App\Models\WalletCashReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $storeA;

    private InventoryLocation $storeB;

    private ExpenseCategory $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);

        $this->domain = Domain::query()->create([
            'name' => 'Expense Org',
            'name_slug' => 'expense-org-'.Str::lower(Str::random(8)),
        ]);
        $this->storeA = $this->makeLocation('Store A', true);
        $this->storeB = $this->makeLocation('Store B');
        ExpenseCategory::ensureDefaults($this->domain->name_slug);
        $this->rent = ExpenseCategory::query()->forDomain($this->domain->name_slug)->where('name', 'Rent')->firstOrFail();
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

    private function route(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug] + $params);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'expense_category_id' => $this->rent->id,
            'amount' => 1500,
            'expense_date' => now()->toDateString(),
            'description' => 'October rent',
            'payment_method' => 'cash_register',
            'location_id' => $this->storeA->id,
        ], $overrides);
    }

    public function test_index_creates_default_categories_and_summarises_the_period(): void
    {
        $admin = $this->makeUser('admin', 2);
        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload([
            'amount' => 500, 'payment_method' => 'bank', 'location_id' => null, 'description' => 'Accountant',
        ]))->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get($this->route('expenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Expenses/Index', false)
                ->has('items.data', 2)
                ->where('summary.total', 2000)
                ->where('summary.count', 2)
                ->where('summary.by_category.0.name', 'Rent')
                ->has('options.categories', count(ExpenseCategory::DEFAULTS))
                ->where('canSeeAllStores', true)
            );

        $this->actingAs($admin)
            ->get($this->route('expenses.index', ['location' => 'business']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.location_name', null));
    }

    public function test_cash_register_expense_is_kept_in_step_with_the_wallet_ledger(): void
    {
        $admin = $this->makeUser('admin', 2);

        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload())->assertSessionHasNoErrors();
        $expense = Expense::query()->firstOrFail();
        $movement = WalletCashMovement::query()->findOrFail($expense->wallet_cash_movement_id);
        $this->assertSame('out', $movement->direction);
        $this->assertSame('expense', $movement->kind);
        $this->assertEquals(1500, $movement->amount);
        $this->assertSame($this->storeA->id, (int) $movement->location_id);

        // Changing the amount updates the same ledger row.
        $this->actingAs($admin)->put($this->route('expenses.update', ['expense' => $expense->id]), $this->payload(['amount' => 1800]))
            ->assertSessionHasNoErrors();
        $this->assertEquals(1800, $movement->fresh()->amount);

        // Paying by bank instead takes it out of the drawer ledger.
        $this->actingAs($admin)->put($this->route('expenses.update', ['expense' => $expense->id]), $this->payload(['payment_method' => 'bank']))
            ->assertSessionHasNoErrors();
        $this->assertNull($movement->fresh());
        $this->assertNull($expense->fresh()->wallet_cash_movement_id);

        // Back to cash, then delete: the ledger row is created again and removed with the expense.
        $this->actingAs($admin)->put($this->route('expenses.update', ['expense' => $expense->id]), $this->payload())
            ->assertSessionHasNoErrors();
        $movementId = $expense->fresh()->wallet_cash_movement_id;
        $this->assertNotNull($movementId);

        $this->actingAs($admin)->delete($this->route('expenses.destroy', ['expense' => $expense->id]))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
        $this->assertDatabaseMissing('wallet_cash_movements', ['id' => $movementId]);
    }

    public function test_cash_expense_cannot_land_on_a_closed_shift(): void
    {
        $admin = $this->makeUser('admin', 2);
        WalletCashReconciliation::query()->create([
            'domain' => $this->domain->name_slug,
            'location_id' => $this->storeA->id,
            'business_date' => now()->toDateString(),
            'is_closed' => true,
        ]);

        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload())->assertSessionHasErrors('expense_date');
        $this->assertSame(0, Expense::query()->count());

        // Paid by bank, the drawer isn't involved, so it's allowed.
        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload(['payment_method' => 'bank']))
            ->assertSessionHasNoErrors();
    }

    public function test_cash_register_expense_needs_a_store(): void
    {
        $admin = $this->makeUser('admin', 2);

        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload(['location_id' => null]))
            ->assertSessionHasErrors('location_id');
    }

    public function test_manager_is_limited_to_their_own_store(): void
    {
        $admin = $this->makeUser('admin', 2);
        $manager = $this->makeUser('manager', 3, $this->storeA);

        // A manager can't file an expense against another store or business-wide.
        $this->actingAs($manager)->post($this->route('expenses.store'), $this->payload([
            'location_id' => $this->storeB->id, 'payment_method' => 'bank',
        ]))->assertSessionHasNoErrors();
        $this->assertSame($this->storeA->id, (int) Expense::query()->latest('id')->first()->location_id);

        $businessWide = Expense::query()->create($this->payload([
            'domain' => $this->domain->name_slug, 'location_id' => null, 'payment_method' => 'bank', 'user_id' => $admin->id,
        ]));
        $otherStore = Expense::query()->create($this->payload([
            'domain' => $this->domain->name_slug, 'location_id' => $this->storeB->id, 'payment_method' => 'bank', 'user_id' => $admin->id,
        ]));

        // Sees own store + business-wide (read-only), not the other store.
        $this->actingAs($manager)
            ->get($this->route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 2)
                ->where('canSeeAllStores', false)
                ->where('restrictedLocationId', $this->storeA->id)
                ->has('options.locations', 1)
            );

        $this->actingAs($manager)->put($this->route('expenses.update', ['expense' => $businessWide->id]), $this->payload())->assertForbidden();
        $this->actingAs($manager)->delete($this->route('expenses.destroy', ['expense' => $otherStore->id]))->assertNotFound();
    }

    public function test_cashier_cannot_use_expenses(): void
    {
        $cashier = $this->makeUser('cashier', 5, $this->storeA);

        $this->actingAs($cashier)->get($this->route('expenses.index'))->assertForbidden();
        $this->actingAs($cashier)->post($this->route('expenses.store'), $this->payload())->assertForbidden();
    }

    public function test_receipt_is_stored_privately_and_served_only_within_the_business(): void
    {
        Storage::fake('expense_receipts');
        $admin = $this->makeUser('admin', 2);

        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload([
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ]))->assertSessionHasNoErrors();

        $expense = Expense::query()->firstOrFail();
        $this->assertNotNull($expense->receipt_path);
        Storage::disk('expense_receipts')->assertExists($expense->receipt_path);

        $this->actingAs($admin)->get($this->route('expenses.receipt', ['expense' => $expense->id]))->assertOk();

        $other = Domain::query()->create(['name' => 'Other', 'name_slug' => 'other-'.Str::lower(Str::random(8))]);
        $this->actingAs($admin)
            ->get(route('domains.expenses.receipt', ['domain' => $other->name_slug, 'expense' => $expense->id]))
            ->assertNotFound();

        // Removing the receipt deletes the file.
        $path = $expense->receipt_path;
        $this->actingAs($admin)->put($this->route('expenses.update', ['expense' => $expense->id]), $this->payload(['remove_receipt' => true]))
            ->assertSessionHasNoErrors();
        $this->assertNull($expense->fresh()->receipt_path);
        Storage::disk('expense_receipts')->assertMissing($path);
    }

    public function test_export_streams_the_filtered_expenses(): void
    {
        $admin = $this->makeUser('admin', 2);
        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload(['payee' => 'Landlord Inc']));

        $response = $this->actingAs($admin)->get($this->route('expenses.export'));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('date,category,description,payee,store,paid_from', $csv);
        $this->assertStringContainsString('Rent,"October rent","Landlord Inc","Store A",cash_register', $csv);
        $this->assertStringContainsString('1500.00', $csv);
    }

    public function test_used_category_can_only_be_deactivated(): void
    {
        $admin = $this->makeUser('admin', 2);
        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload(['payment_method' => 'bank']));

        $this->actingAs($admin)->delete($this->route('expenses.categories.destroy', ['category' => $this->rent->id]))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('expense_categories', ['id' => $this->rent->id]);

        $this->actingAs($admin)->put($this->route('expenses.categories.update', ['category' => $this->rent->id]), ['is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->rent->fresh()->is_active);

        // Inactive categories can't be picked for new expenses.
        $this->actingAs($admin)->post($this->route('expenses.store'), $this->payload(['payment_method' => 'bank']))
            ->assertSessionHasErrors('expense_category_id');
    }
}
