<?php

namespace Tests\Feature\Licensing;

use App\Http\Middleware\RoleBasedAccessControl;
use App\Http\Middleware\UserPermissionCheckMiddleware;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\Licensing\OrganizationLicensingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineLicenseMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            UserPermissionCheckMiddleware::class,
            RoleBasedAccessControl::class,
        ]);
    }

    public function test_offline_sync_forbidden_without_usable_org_license(): void
    {
        $domain = Domain::query()->create([
            'name' => 'No License Org',
            'name_slug' => 'nolic-'.Str::lower(Str::random(6)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'status' => 'active',
            'location_id' => $location->id,
        ]);

        $url = route('domains.sales.offline-sync', ['domain' => $domain->name_slug]);
        $this->actingAs($user)
            ->postJson($url, ['sales' => []])
            ->assertForbidden();
    }

    public function test_offline_sync_passes_middleware_with_usable_license(): void
    {
        $domain = Domain::query()->create([
            'name' => 'With License Org',
            'name_slug' => 'wlic-'.Str::lower(Str::random(6)),
        ]);

        $location = InventoryLocation::query()->create([
            'domain' => $domain->name_slug,
            'name' => 'Main',
            'code' => Str::upper(Str::random(8)),
            'type' => 'store',
            'is_active' => true,
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'status' => 'active',
            'location_id' => $location->id,
        ]);

        $service = app(OrganizationLicensingService::class);
        $service->createAndActivateForDomain($domain, [
            'max_usages' => 10,
        ]);

        $url = route('domains.sales.offline-sync', ['domain' => $domain->name_slug]);
        $res = $this->actingAs($user)->postJson($url, ['sales' => []]);
        $this->assertNotSame(403, $res->getStatusCode());
    }
}
