<?php

namespace Tests\Feature\Licensing;

use App\Models\Domain;
use App\Services\Licensing\OrganizationLicensingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DomainLicenseMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('licensing:keys:make-root', ['--force' => true, '--no-interaction' => true]);
        $this->artisan('licensing:keys:issue-signing', ['--kid' => 'phpunit-mw-signing', '--no-interaction' => true]);

        Route::get('/_test_license_mw/{domain}', fn () => response('ok', 200))
            ->middleware('license.domain');
    }

    public function test_middleware_passes_when_enforcement_disabled(): void
    {
        config(['techiko-licensing.enforce_domain_license' => false]);

        $domain = Domain::create([
            'name' => 'No License Org',
            'timezone' => 'UTC',
            'country_code' => 'PH',
            'is_active' => true,
        ]);

        $this->get('/_test_license_mw/'.$domain->name_slug)
            ->assertOk()
            ->assertSee('ok');
    }

    public function test_middleware_returns_403_when_enforced_and_no_license(): void
    {
        config(['techiko-licensing.enforce_domain_license' => true]);

        $domain = Domain::create([
            'name' => 'Unlicensed Org',
            'timezone' => 'UTC',
            'country_code' => 'PH',
            'is_active' => true,
        ]);

        $this->get('/_test_license_mw/'.$domain->name_slug)
            ->assertForbidden();
    }

    public function test_middleware_allows_when_enforced_and_license_exists(): void
    {
        config(['techiko-licensing.enforce_domain_license' => true]);

        $domain = Domain::create([
            'name' => 'Licensed Org',
            'timezone' => 'UTC',
            'country_code' => 'PH',
            'is_active' => true,
        ]);

        app(OrganizationLicensingService::class)->issueLicenseForDomain($domain, maxSeats: 1);

        $this->get('/_test_license_mw/'.$domain->name_slug)
            ->assertOk()
            ->assertSee('ok');
    }
}
