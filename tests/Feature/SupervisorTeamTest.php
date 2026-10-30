<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A supervisor sees and reviews the shifts of the cashiers assigned to her (users.supervisor_id),
 * not every cashier at the store. Managers and up see everyone's. Owners see each shift's store.
 */
class SupervisorTeamTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        $this->domain = Domain::query()->create(['name' => 'Team Org', 'name_slug' => 'team-'.Str::lower(Str::random(6))]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Makati', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    /** @param  list<string>  $routes */
    private function user(string $role, int $level, array $routes = [], ?User $supervisor = null): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $r->forceFill(['level' => $level])->save();
        $user = User::factory()->create([
            'domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id,
            'supervisor_id' => $supervisor?->id,
        ]);
        $user->assignRole($r);
        foreach ($routes as $route) {
            $user->givePermissionTo(Permission::query()->firstOrCreate(['route_name' => $route, 'guard_name' => 'web'], ['name' => 'Test '.$route.' '.Str::uuid()]));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A shift of this cashier that ended ₱10 short: waiting for review. */
    private function shortShift(User $cashier): RegisterShift
    {
        $this->actingAs($cashier)->post($this->url('register-shifts.start'), ['location_id' => $this->store->id, 'opening_cash' => 100])->assertSessionHasNoErrors();
        $shift = RegisterShift::query()->where('user_id', $cashier->id)->latest('id')->firstOrFail();
        $this->actingAs($cashier)->post($this->url('register-shifts.end', ['registerShift' => $shift->id]), ['counted_cash' => 90])->assertSessionHasNoErrors();

        return $shift->fresh();
    }

    public function test_a_supervisor_sees_and_reviews_only_her_team(): void
    {
        $review = ['register-shifts.index', 'register-shifts.approve', 'register-shifts.reopen'];
        $sue = $this->user('supervisor', 4, $review);
        $other = $this->user('supervisor', 4, $review);
        $ana = $this->user('cashier', 5, [], $sue);
        $ben = $this->user('cashier', 5, [], $other);
        $anaShift = $this->shortShift($ana);
        $benShift = $this->shortShift($ben);

        // Today's drawer: Ana's shift, not Ben's; her team named; no store-wide summary.
        $this->actingAs($sue)->get($this->url('wallet.drawer'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('drawerByShift.rows', fn ($rows) => collect($rows)->pluck('user.id')->all() === [$ana->id])
            ->where('requiredActions', fn ($rows) => collect($rows)->pluck('id')->all() === [$anaShift->id])
            ->where('needsReviewCount', 1)
            ->where('seesWholeStore', false)
            ->where('myTeam', fn ($names) => collect($names)->all() === [$ana->name])
            ->where('drawerSummary', null));

        // Shifts & counts: the same.
        $this->actingAs($sue)->get($this->url('register-shifts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('shifts.data', 1)
            ->where('shifts.data.0.id', $anaShift->id)
            ->where('statusCounts.needs_review', 1));

        // Ben's shift isn't hers to open, approve or send back.
        $this->actingAs($sue)->getJson($this->url('register-shifts.show', ['registerShift' => $benShift->id]))->assertNotFound();
        $this->actingAs($sue)->post($this->url('register-shifts.approve', ['registerShift' => $benShift->id]), [])->assertSessionHasErrors('shift');
        $this->actingAs($sue)->post($this->url('register-shifts.reopen', ['registerShift' => $benShift->id]), ['reason' => 'Count again'])->assertSessionHasErrors('shift');
        $this->assertSame(RegisterShift::NEEDS_REVIEW, $benShift->fresh()->status);

        // Ana's is.
        $this->actingAs($sue)->getJson($this->url('register-shifts.show', ['registerShift' => $anaShift->id]))->assertOk();
        $this->actingAs($sue)->post($this->url('register-shifts.approve', ['registerShift' => $anaShift->id]), [])->assertSessionHasNoErrors();
        $this->assertSame(RegisterShift::APPROVED, $anaShift->fresh()->status);
    }

    public function test_a_manager_sees_and_reviews_everyone_and_an_owner_sees_each_shifts_store(): void
    {
        $sue = $this->user('supervisor', 4);
        $ana = $this->user('cashier', 5, [], $sue);
        $ben = $this->user('cashier', 5);
        $this->shortShift($ana);
        $benShift = $this->shortShift($ben);
        $boss = $this->user('manager', 3, ['register-shifts.index', 'register-shifts.approve']);

        $this->actingAs($boss)->get($this->url('register-shifts.index'))->assertInertia(fn (Assert $page) => $page
            ->has('shifts.data', 2)
            ->where('showStore', false));
        $this->actingAs($boss)->post($this->url('register-shifts.approve', ['registerShift' => $benShift->id]), [])->assertSessionHasNoErrors();

        // An owner approves across stores: every shift names its store.
        $owner = $this->user('admin', 2, ['register-shifts.index', 'register-shifts.approve']);
        $this->actingAs($owner)->get($this->url('register-shifts.index', ['status' => 'all', 'location_id' => $this->store->id]))->assertInertia(fn (Assert $page) => $page
            ->where('showStore', true)
            ->where('shifts.data.0.store', 'Makati'));
    }
}
