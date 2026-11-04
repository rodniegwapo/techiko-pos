<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\RegisterShift;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserPin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Opening your drawer can clock you in, and closing it can clock you out, with your own PIN: the
 * drawer and the punch go together or not at all. Everyone can change their own PIN on Profile.
 */
class DrawerClockTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class, ValidateCsrfToken::class]);

        $this->domain = Domain::query()->create(['name' => 'Clock Org', 'name_slug' => 'clock-org-'.Str::lower(Str::random(6))]);
        $this->store = InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $this->ana = $this->user('cashier', 4);
        $this->pin($this->ana, '1111');
    }

    private function user(string $role, int $level): User
    {
        $r = Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $r->forceFill(['level' => $level])->save();
        $user = User::factory()->create(['domain' => $this->domain->name_slug, 'is_super_user' => false, 'location_id' => $this->store->id]);
        $user->assignRole($r);

        return $user;
    }

    private function pin(User $user, string $plain): void
    {
        UserPin::query()->updateOrCreate(['user_id' => $user->id], ['pin_code' => Hash::make($plain), 'active' => true]);
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    private function open(User $user, ?string $pin = null)
    {
        return $this->actingAs($user)->post($this->url('register-shifts.start'), [
            'location_id' => $this->store->id, 'opening_cash' => 500, 'clock_in_pin' => $pin,
        ]);
    }

    private function close(User $as, RegisterShift $shift, ?string $pin = null)
    {
        return $this->actingAs($as)->post($this->url('register-shifts.end', ['registerShift' => $shift->id]), [
            'counted_cash' => 500, 'clock_out_pin' => $pin,
        ]);
    }

    private function entries(User $user)
    {
        return TimeEntry::query()->where('user_id', $user->id)->get();
    }

    private function shiftOf(User $user): RegisterShift
    {
        return RegisterShift::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function test_opening_the_drawer_with_your_pin_clocks_you_in_too(): void
    {
        $this->open($this->ana, '1111')->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, "Drawer opened. You're clocked in at"));

        $this->assertSame(RegisterShift::OPEN, $this->shiftOf($this->ana)->status);
        $this->assertCount(1, $this->entries($this->ana));
        $this->assertNull($this->entries($this->ana)->first()->clock_out_at);
        $this->assertSame($this->store->id, (int) $this->entries($this->ana)->first()->location_id);
    }

    public function test_without_a_pin_it_only_opens_the_drawer(): void
    {
        $this->open($this->ana)->assertSessionHasNoErrors()->assertSessionHas('success', 'Drawer opened.');

        $this->assertSame(RegisterShift::OPEN, $this->shiftOf($this->ana)->status);
        $this->assertCount(0, $this->entries($this->ana));
    }

    public function test_a_wrong_pin_or_someone_elses_saves_nothing_and_counts_as_a_wrong_try(): void
    {
        $ben = $this->user('cashier', 4);
        $this->pin($ben, '2222');

        $this->open($this->ana, '9999')->assertSessionHasErrors(['clock_in_pin' => 'That PIN is not right.']);
        $this->open($this->ana, '2222')->assertSessionHasErrors(['clock_in_pin' => "That PIN isn't yours."]);

        $this->assertSame(0, RegisterShift::query()->where('user_id', $this->ana->id)->count());
        $this->assertCount(0, $this->entries($this->ana));
        $this->assertCount(0, $this->entries($ben));
        $this->assertSame(2, RateLimiter::attempts('timeclock:'.$this->ana->id.'|127.0.0.1'));
    }

    public function test_already_on_the_clock_the_pin_is_ignored_and_no_second_entry_starts(): void
    {
        TimeEntry::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id,
            'user_id' => $this->ana->id, 'clock_in_at' => now()->subHour(),
        ]);

        // Even a wrong PIN doesn't matter: there's nothing to clock in.
        $this->open($this->ana, '9999')->assertSessionHasNoErrors()->assertSessionHas('success', 'Drawer opened.');
        $this->assertCount(1, $this->entries($this->ana));
    }

    public function test_closing_your_drawer_with_your_pin_clocks_you_out_too(): void
    {
        $this->open($this->ana, '1111');

        $this->close($this->ana, $this->shiftOf($this->ana), '1111')->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, "You're clocked out at"));

        $this->assertSame(RegisterShift::CLOSED, $this->shiftOf($this->ana)->status);
        $this->assertNotNull($this->entries($this->ana)->first()->clock_out_at);
    }

    public function test_a_wrong_pin_on_close_keeps_the_drawer_open_and_you_on_the_clock(): void
    {
        $this->open($this->ana, '1111');

        $this->close($this->ana, $this->shiftOf($this->ana), '0000')->assertSessionHasErrors('clock_out_pin');

        $this->assertSame(RegisterShift::OPEN, $this->shiftOf($this->ana)->status);
        $this->assertNull($this->entries($this->ana)->first()->clock_out_at);
    }

    public function test_closing_without_a_pin_leaves_you_on_the_clock(): void
    {
        $this->open($this->ana, '1111');

        $this->close($this->ana, $this->shiftOf($this->ana))->assertSessionHasNoErrors()->assertSessionHas('success', 'Drawer closed.');

        $this->assertSame(RegisterShift::CLOSED, $this->shiftOf($this->ana)->status);
        $this->assertNull($this->entries($this->ana)->first()->clock_out_at);
    }

    public function test_a_manager_closing_someone_elses_drawer_never_clocks_them_out(): void
    {
        $boss = $this->user('manager', 3);
        foreach (['wallet-cash-ledger.index', 'register-shifts.money-by-user'] as $route) {
            $boss->givePermissionTo(Permission::query()->firstOrCreate(['route_name' => $route, 'guard_name' => 'web'], ['name' => 'Test '.$route.' '.Str::uuid()]));
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->open($this->ana, '1111');

        // Even with Ana's PIN.
        $this->close($boss->fresh(), $this->shiftOf($this->ana), '1111')->assertSessionHasNoErrors()->assertSessionHas('success', 'Drawer closed.');

        $this->assertSame(RegisterShift::CLOSED, $this->shiftOf($this->ana)->status);
        $this->assertNull($this->entries($this->ana)->first()->clock_out_at);
    }

    public function test_the_sales_and_drawer_pages_say_whether_you_are_on_the_clock(): void
    {
        $this->actingAs($this->ana)->get($this->url('wallet.drawer'))->assertInertia(fn (Assert $page) => $page
            ->where('myClock.on_the_clock', false)
            ->where('myClock.has_pin', true));

        $this->open($this->ana, '1111');

        $this->actingAs($this->ana)->get($this->url('wallet.drawer'))->assertInertia(fn (Assert $page) => $page
            ->where('myClock.on_the_clock', true)
            ->where('myClock.since', fn ($v) => $v !== null));
    }

    public function test_you_can_change_your_own_pin_with_your_password(): void
    {
        $this->actingAs($this->ana)->put(route('profile.pin.update'), [
            'current_password' => 'password', 'pin_code' => '5678', 'pin_code_confirmation' => '5678',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('5678', UserPin::query()->where('user_id', $this->ana->id)->value('pin_code')));
        $this->open($this->ana, '5678')->assertSessionHasNoErrors();
        $this->assertCount(1, $this->entries($this->ana));
    }

    public function test_changing_your_pin_needs_your_password_a_free_pin_and_4_to_6_digits(): void
    {
        $ben = $this->user('cashier', 4);
        $this->pin($ben, '2222');
        $put = fn (array $data) => $this->actingAs($this->ana)->put(route('profile.pin.update'), [
            'current_password' => 'password', 'pin_code_confirmation' => $data['pin_code'] ?? null, ...$data,
        ]);

        $put(['current_password' => 'wrong', 'pin_code' => '5678'])->assertSessionHasErrors('current_password');
        $put(['pin_code' => '2222'])->assertSessionHasErrors('pin_code');
        $put(['pin_code' => '12'])->assertSessionHasErrors('pin_code');
        $put(['pin_code' => '5678', 'pin_code_confirmation' => '5679'])->assertSessionHasErrors('pin_code');

        $this->assertTrue(Hash::check('1111', UserPin::query()->where('user_id', $this->ana->id)->value('pin_code')));
    }

    public function test_the_profile_page_says_whether_you_have_a_pin(): void
    {
        $this->actingAs($this->ana)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('canSetPin', true)
            ->where('hasPin', true));
    }
}
