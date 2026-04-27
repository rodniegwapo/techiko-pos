<?php

namespace Tests\Feature\Licensing;

use App\Models\Domain;
use App\Models\User;
use App\Services\Licensing\OrganizationLicensingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicensingApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Required for PASETO issuance (offline token). Migrations do not create keys.
     */
    private function ensureLicensingSigningKeysExist(): void
    {
        $this->artisan('licensing:keys:make-root', [
            '--no-interaction' => true,
            '--force' => true,
        ]);
        $this->artisan('licensing:keys:issue-signing', [
            '--no-interaction' => true,
            '--kid' => 'phpunit-signing',
        ]);
    }

    /**
     * @return array{domain: Domain, user: User}
     */
    private function domainUserWithLicense(int $maxUsages): array
    {
        $domain = Domain::query()->create([
            'name' => 'Licensing Test Org',
            'name_slug' => 'lic-test-'.Str::lower(Str::random(8)),
        ]);

        $user = User::factory()->create([
            'domain' => $domain->name_slug,
            'is_super_user' => false,
            'status' => 'active',
        ]);

        $service = app(OrganizationLicensingService::class);
        $service->createAndActivateForDomain($domain, [
            'max_usages' => $maxUsages,
        ]);

        return compact('domain', 'user');
    }

    public function test_register_device_and_offline_token_succeeds_with_valid_license(): void
    {
        $this->ensureLicensingSigningKeysExist();
        $ctx = $this->domainUserWithLicense(2);
        $token = $ctx['user']->createToken('test')->plainTextToken;
        $fp = 'fingerprint-'.Str::random(10);

        $r1 = $this->withToken($token)->postJson('/api/licensing/register-device', [
            'fingerprint' => $fp,
            'device_name' => 'Test',
        ]);
        $r1->assertOk()
            ->assertJsonStructure(['license_uid', 'usage_id']);

        $r2 = $this->withToken($token)->postJson('/api/licensing/offline-token', [
            'fingerprint' => $fp,
            'ttl_days' => 7,
        ]);
        $r2->assertOk()
            ->assertJsonPath('ttl_days', 7);
        $this->assertIsString($r2->json('token'));
        $this->assertNotSame('', $r2->json('token'));
    }

    public function test_second_device_fails_when_seat_limit_reached(): void
    {
        $ctx = $this->domainUserWithLicense(1);
        $token = $ctx['user']->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/licensing/register-device', [
            'fingerprint' => 'fingerprint-a',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/licensing/register-device', [
            'fingerprint' => 'fingerprint-b',
        ])->assertStatus(422);
    }

    public function test_super_user_cannot_use_licensing_api(): void
    {
        $user = User::factory()->create(['is_super_user' => true]);
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/licensing/register-device', [
            'fingerprint' => 'fingerprint-super',
        ])->assertForbidden();
    }
}
