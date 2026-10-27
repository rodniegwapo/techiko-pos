<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\WalletCashReconciliation;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The Sales page tells "no shift started yet" apart from "today's shift was already ended". */
class SalesShiftStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class, ValidateCsrfToken::class]);
    }

    /** @return array{0: Domain, 1: InventoryLocation, 2: User} */
    private function context(): array
    {
        $domain = Domain::query()->create(['name' => 'Org', 'name_slug' => 'org-'.Str::lower(Str::random(6))]);
        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => true, 'location_id' => $location->id]);

        return [$domain, $location, $user];
    }

    private function shiftStatus(Domain $domain, User $user): array
    {
        $props = null;
        $this->actingAs($user)->get(route('domains.sales.index', ['domain' => $domain->name_slug]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props']['shiftStatus'];
            });

        return $props;
    }

    public function test_no_row_means_not_started_and_not_closed(): void
    {
        [$domain, , $user] = $this->context();

        $status = $this->shiftStatus($domain, $user);

        $this->assertFalse($status['is_open']);
        $this->assertFalse($status['is_closed']);
    }

    public function test_an_ended_shift_is_reported_closed_and_anyone_with_the_permission_can_reopen(): void
    {
        [$domain, $location, $user] = $this->context();
        $other = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => true, 'location_id' => $location->id]);

        WalletCashReconciliation::query()->create([
            'domain' => $domain->name_slug, 'location_id' => $location->id,
            'business_date' => now()->toDateString(), 'opening_cash' => 0,
            'is_closed' => true, 'closed_at' => now(), 'closed_by' => $user->id,
        ]);

        // Super users hold every permission, so both can reopen, not just the one who ended it.
        $mine = $this->shiftStatus($domain, $user);
        $this->assertTrue($mine['is_closed']);
        $this->assertFalse($mine['is_open']);
        $this->assertTrue($mine['can_reopen']);

        $theirs = $this->shiftStatus($domain, $other);
        $this->assertTrue($theirs['is_closed']);
        $this->assertTrue($theirs['can_reopen']);
        $this->assertSame($user->name, $theirs['closed_by_name']);

        // A user without the reopen permission cannot.
        $staff = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => false, 'role_level' => 5, 'location_id' => $location->id]);
        $this->assertFalse($this->shiftStatus($domain, $staff)['can_reopen']);
    }
}
