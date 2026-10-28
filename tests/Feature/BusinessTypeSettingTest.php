<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Settings → Business type: what the business sells, which presets the product form. It is asked at
 * signup, saved with the other sales settings, and shared with every page; none means retail.
 */
class BusinessTypeSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([UserPermissionCheckMiddleware::class, RoleBasedAccessControl::class]);
    }

    private function organization(array $settings = []): array
    {
        $domain = Domain::query()->create([
            'name' => 'Type Org',
            'name_slug' => 'type-org-'.Str::lower(Str::random(8)),
            'settings' => $settings,
        ]);
        $user = User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => true]);

        return [$domain, $user];
    }

    public function test_an_organization_without_a_type_is_retail(): void
    {
        [$domain] = $this->organization();

        $this->assertSame('retail', $domain->businessType());
        $this->assertFalse($domain->hasChosenBusinessType(), 'its admins are asked to pick one');
        $domain->update(['settings' => ['sales' => ['business_type' => 'bakery']]]);
        $this->assertSame('retail', $domain->fresh()->businessType(), 'an unknown value reads as retail');
        $this->assertFalse($domain->fresh()->hasChosenBusinessType());
    }

    public function test_settings_saves_the_type_and_keeps_the_other_sales_settings(): void
    {
        [$domain, $user] = $this->organization(['sales' => ['allow_overselling' => true]]);
        $url = route('domains.settings.update', ['domain' => $domain->name_slug]);

        $this->actingAs($user)->patch($url, ['business_type' => 'grocery'])->assertSessionHasNoErrors();

        $domain->refresh();
        $this->assertSame('grocery', $domain->businessType());
        $this->assertTrue($domain->salesAllowsOverselling());

        $this->actingAs($user)->patch($url, ['business_type' => 'bakery'])->assertSessionHasErrors('business_type');
        $this->assertSame('grocery', $domain->fresh()->businessType());
    }

    public function test_the_type_is_shared_with_the_pages(): void
    {
        [$domain, $user] = $this->organization(['sales' => ['business_type' => 'food']]);

        $this->actingAs($user)->get(route('domains.settings.index', ['domain' => $domain->name_slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->where('businessType', 'food')
                ->where('businessTypeChosen', true)
                ->where('salesSettings.business_type', 'food')
            );
    }

    public function test_signup_stores_what_the_business_sells(): void
    {
        Event::fake();

        $this->post('/register', [
            'name' => 'Ana Cruz', 'email' => 'ana@example.com', 'password' => 'password', 'password_confirmation' => 'password',
            'organization' => 'Ana Milk Tea', 'business_type' => 'food',
        ])->assertSessionHasNoErrors();

        $domain = Domain::query()->where('name', 'Ana Milk Tea')->firstOrFail();
        $this->assertSame('food', $domain->businessType());
    }

    public function test_signup_without_a_type_is_retail_and_an_unknown_one_is_refused(): void
    {
        Event::fake();

        $this->post('/register', [
            'name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'password', 'password_confirmation' => 'password',
            'organization' => 'Ben Hardware',
        ])->assertSessionHasNoErrors();
        $this->assertSame('retail', Domain::query()->where('name', 'Ben Hardware')->firstOrFail()->businessType());

        $this->post('/register', [
            'name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'password', 'password_confirmation' => 'password',
            'organization' => 'Cy Org', 'business_type' => 'bakery',
        ])->assertSessionHasErrors('business_type');
    }
}
