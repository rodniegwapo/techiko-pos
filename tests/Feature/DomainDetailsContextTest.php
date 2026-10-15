<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** A super admin viewing an organization on the Domains page stays in the admin, not inside that organization. */
class DomainDetailsContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewing_an_organizations_details_does_not_switch_into_it(): void
    {
        $this->withoutMiddleware(RoleBasedAccessControl::class);
        $domain = Domain::query()->create(['name' => 'Viewed Org', 'name_slug' => 'viewed-org-'.Str::lower(Str::random(6))]);
        $admin = User::factory()->create(['domain' => null, 'is_super_user' => true]);

        $this->actingAs($admin)->get(route('domains.show', $domain))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Domains/Show')
                ->where('domain.data.name_slug', $domain->name_slug)
                ->where('inOrganization', false)
                ->where('currentDomain', null)
                ->where('availableLocations', [])
                ->etc());

        $this->actingAs($admin)->get(route('domains.dashboard', $domain))
            ->assertInertia(fn (Assert $page) => $page
                ->where('inOrganization', true)
                ->where('currentDomain.name_slug', $domain->name_slug)
                ->etc());
    }
}
