<?php

namespace Tests\Feature\Licensing;

use App\Models\Domain;
use App\Services\Licensing\OrganizationLicensingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;
use Tests\TestCase;

class OrganizationLicensingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('licensing:keys:make-root', ['--force' => true, '--no-interaction' => true]);
        $this->artisan('licensing:keys:issue-signing', ['--kid' => 'phpunit-signing', '--no-interaction' => true]);
    }

    public function test_issue_license_for_domain_creates_active_license_with_key(): void
    {
        $domain = Domain::create([
            'name' => 'Test Org',
            'timezone' => 'UTC',
            'country_code' => 'PH',
            'is_active' => true,
        ]);

        $service = app(OrganizationLicensingService::class);
        $license = $service->issueLicenseForDomain($domain, maxSeats: 3);

        $this->assertSame(LicenseStatus::Active, $license->status);
        $this->assertSame(3, $license->max_usages);
        $this->assertNotNull($license->license_key);
        $this->assertTrue($license->isUsable());
        $this->assertTrue($domain->licenses()->whereKey($license->getKey())->exists());
    }
}
