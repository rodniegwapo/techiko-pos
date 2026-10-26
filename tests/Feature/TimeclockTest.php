<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\UserPin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Clocking in and out on the POS with a PIN, and the manager's timesheets. */
class TimeclockTest extends TestCase
{
    use RefreshDatabase;

    private Domain $domain;

    private InventoryLocation $store;

    private InventoryLocation $branch;

    private User $till;

    private User $juan;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class, ValidateCsrfToken::class]);
        // Wednesday 8:00 in Manila (the organization's time zone).
        Carbon::setTestNow(Carbon::parse('2026-10-28 08:00', 'Asia/Manila')->utc());

        $this->domain = Domain::query()->create(['name' => 'Sari-sari', 'name_slug' => 'sari-'.Str::lower(Str::random(6))]);
        $make = fn (string $name, bool $default) => InventoryLocation::query()->create([
            'domain' => $this->domain->name_slug, 'name' => $name, 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => $default,
        ]);
        $this->store = $make('Main', true);
        $this->branch = $make('Branch', false);

        $slug = $this->domain->name_slug;
        $this->till = User::factory()->create(['domain' => $slug, 'location_id' => $this->store->id]);
        $this->juan = User::factory()->create(['domain' => $slug, 'name' => 'Juan', 'location_id' => $this->store->id]);
        $this->admin = User::factory()->create(['domain' => $slug, 'is_super_user' => true, 'location_id' => $this->store->id]);
        $this->pin($this->juan, '4321');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pin(User $user, string $plain, bool $active = true): void
    {
        UserPin::query()->create(['user_id' => $user->id, 'pin_code' => Hash::make($plain), 'active' => $active]);
    }

    private function url(string $name, array $params = []): string
    {
        return route('domains.'.$name, ['domain' => $this->domain->name_slug, ...$params]);
    }

    private function punch(string $pin, ?InventoryLocation $at = null)
    {
        return $this->actingAs($this->till)->postJson($this->url('timesheets.punch'), [
            'pin' => $pin, 'location_id' => ($at ?? $this->store)->id,
        ]);
    }

    public function test_a_pin_clocks_in_then_out_and_says_how_long_they_worked(): void
    {
        $this->punch('4321')->assertOk()
            ->assertJsonPath('action', 'in')
            ->assertJsonPath('user.name', 'Juan');
        $this->assertSame(1, TimeEntry::query()->open()->count());

        Carbon::setTestNow(now()->addHours(8)->addMinutes(15));
        $this->punch('4321')->assertOk()
            ->assertJsonPath('action', 'out')
            ->assertJsonPath('worked_minutes', 495)
            ->assertJsonPath('minutes_today', 495);

        $entry = TimeEntry::query()->sole();
        $this->assertNotNull($entry->clock_out_at);
        $this->assertSame($this->store->id, (int) $entry->location_id);
        $this->assertSame($this->juan->id, (int) $entry->user_id);
    }

    public function test_a_wrong_pin_is_refused_and_too_many_wrong_tries_make_the_device_wait(): void
    {
        RateLimiter::clear('timeclock:'.$this->till->id.'|127.0.0.1');

        $this->punch('0000')->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->assertSame(0, TimeEntry::query()->count());

        foreach (range(1, 4) as $i) {
            $this->punch('0000')->assertStatus(422);
        }
        // Even the right PIN waits once the device has had five wrong ones.
        $this->punch('4321')->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_a_pin_from_another_organization_or_a_switched_off_pin_is_not_found(): void
    {
        $other = Domain::query()->create(['name' => 'Other', 'name_slug' => 'other-'.Str::lower(Str::random(6))]);
        $stranger = User::factory()->create(['domain' => $other->name_slug]);
        $this->pin($stranger, '9999');
        $off = User::factory()->create(['domain' => $this->domain->name_slug]);
        $this->pin($off, '5555', active: false);

        $this->punch('9999')->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->punch('5555')->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_a_forgotten_clock_out_stays_open_and_the_next_punch_starts_a_new_shift(): void
    {
        $this->punch('4321')->assertOk()->assertJsonPath('action', 'in');

        Carbon::setTestNow(now()->addHours(30));
        $this->punch('4321')->assertOk()
            ->assertJsonPath('action', 'in')
            ->assertJsonPath('minutes_today', 0);

        $this->assertSame(2, TimeEntry::query()->count());
        $this->assertSame(1, TimeEntry::query()->forgotten()->count());
        $this->assertSame(1, TimeEntry::query()->onTheClock()->count());
    }

    public function test_the_timesheet_adds_up_hours_per_person_and_flags_whoever_forgot(): void
    {
        $this->punch('4321');
        Carbon::setTestNow(now()->addHours(8)->addMinutes(15));
        $this->punch('4321');

        $maria = User::factory()->create(['domain' => $this->domain->name_slug, 'name' => 'Maria']);
        TimeEntry::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'user_id' => $maria->id,
            'clock_in_at' => now()->subHours(20),
        ]);

        $this->actingAs($this->admin)->get($this->url('timesheets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Timesheets/Index')
                ->where('totalMinutes', 495)
                ->where('people.0.name', 'Juan')
                ->where('people.0.minutes', 495)
                ->where('people.0.days.0.entries.0.clock_in_at', '2026-10-28 08:00')
                ->where('people.0.days.0.entries.0.clock_out_at', '2026-10-28 16:15')
                ->has('forgotten', 1)
                ->where('forgotten.0.user', 'Maria')
                ->has('onTheClock', 0));
    }

    public function test_a_manager_edit_closes_a_forgotten_shift_and_is_logged(): void
    {
        $entry = TimeEntry::query()->create([
            'domain' => $this->domain->name_slug, 'location_id' => $this->store->id, 'user_id' => $this->juan->id,
            'clock_in_at' => now()->subHours(20),
        ]);

        $this->actingAs($this->admin)->put($this->url('timesheets.update', ['timeEntry' => $entry->id]), [
            'clock_in_at' => '2026-10-27 12:00',
            'clock_out_at' => '2026-10-27 20:00',
            'reason' => 'Forgot to clock out',
        ])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame(480, $entry->minutes());
        $this->assertSame($this->admin->id, (int) $entry->edited_by);

        $edit = $entry->edits()->sole();
        $this->assertSame('Forgot to clock out', $edit->reason);
        $this->assertNull($edit->before['clock_out_at']);
        $this->assertNotNull($edit->after['clock_out_at']);
        $this->assertSame($this->admin->id, (int) $edit->edited_by);
    }

    public function test_a_manager_can_add_a_missed_shift_but_not_an_overlapping_or_impossible_one(): void
    {
        $post = fn (array $over) => $this->actingAs($this->admin)->post($this->url('timesheets.store'), [
            'user_id' => $this->juan->id, 'location_id' => $this->store->id,
            'clock_in_at' => '2026-10-26 08:00', 'clock_out_at' => '2026-10-26 16:00',
            'reason' => 'Till was down', ...$over,
        ]);

        $post([])->assertSessionHasNoErrors();
        $this->assertSame(480, TimeEntry::query()->sole()->minutes());
        $this->assertSame(1, TimeEntry::query()->sole()->edits()->count());

        $post(['clock_in_at' => '2026-10-26 15:00', 'clock_out_at' => '2026-10-26 18:00'])->assertSessionHasErrors('clock_in_at');
        $post(['clock_in_at' => '2026-10-25 09:00', 'clock_out_at' => '2026-10-25 08:00'])->assertSessionHasErrors('clock_out_at');
        $post(['clock_in_at' => '2026-10-24 00:00', 'clock_out_at' => '2026-10-25 06:00'])->assertSessionHasErrors('clock_out_at');
        $post(['clock_in_at' => '2026-11-02 08:00', 'clock_out_at' => '2026-11-02 12:00'])->assertSessionHasErrors('clock_in_at');
        $post(['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_another_organizations_timesheet_cannot_be_read_or_changed(): void
    {
        $other = Domain::query()->create(['name' => 'Other', 'name_slug' => 'other-'.Str::lower(Str::random(6))]);
        $otherStore = InventoryLocation::query()->create([
            'domain' => $other->name_slug, 'name' => 'X', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $stranger = User::factory()->create(['domain' => $other->name_slug]);
        $theirs = TimeEntry::query()->create([
            'domain' => $other->name_slug, 'location_id' => $otherStore->id, 'user_id' => $stranger->id,
            'clock_in_at' => now()->subHours(3), 'clock_out_at' => now()->subHour(),
        ]);

        $this->actingAs($this->admin)->get($this->url('timesheets.index'))
            ->assertInertia(fn (Assert $page) => $page->where('totalMinutes', 0)->has('people', 0));
        $this->actingAs($this->admin)->put($this->url('timesheets.update', ['timeEntry' => $theirs->id]), [
            'clock_in_at' => '2026-10-28 01:00', 'clock_out_at' => '2026-10-28 02:00', 'reason' => 'x',
        ])->assertNotFound();
    }

    public function test_a_manager_works_within_their_own_store_and_the_export_is_a_csv(): void
    {
        $manager = User::factory()->create(['domain' => $this->domain->name_slug, 'location_id' => $this->store->id]);
        $manager->assignRole(Role::findOrCreate('manager', 'web')->forceFill(['level' => 3]));
        Role::query()->where('name', 'manager')->update(['level' => 3]);
        $manager->load('roles');

        foreach ([[$this->store, 3], [$this->branch, 6]] as [$location, $hoursAgo]) {
            TimeEntry::query()->create([
                'domain' => $this->domain->name_slug, 'location_id' => $location->id, 'user_id' => $this->juan->id,
                'clock_in_at' => now()->subHours($hoursAgo), 'clock_out_at' => now()->subHours($hoursAgo - 2),
            ]);
        }

        $this->actingAs($manager)->get($this->url('timesheets.index', ['location_id' => $this->branch->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.location_id', $this->store->id)
                ->where('canPickAllStores', false)
                ->has('people', 1)
                ->where('people.0.days.0.entries.0.location', 'Main'));

        $branchEntry = TimeEntry::query()->where('location_id', $this->branch->id)->sole();
        $this->actingAs($manager)->put($this->url('timesheets.update', ['timeEntry' => $branchEntry->id]), [
            'clock_in_at' => '2026-10-28 01:00', 'clock_out_at' => '2026-10-28 02:00', 'reason' => 'x',
        ])->assertSessionHasErrors('location_id');

        $csv = $this->actingAs($this->admin)->get($this->url('timesheets.export'));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('person,date,clock_in,clock_out,hours,store,status,notes', $body);
        $this->assertStringContainsString('Juan', $body);
    }
}
