<?php

namespace Tests\Feature\Licensing;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DomainLicenseWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_user_can_create_organization_license(): void
    {
        $domain = Domain::query()->create([
            'name' => 'Web License Org',
            'name_slug' => 'web-lic-'.Str::lower(Str::random(6)),
        ]);

        $admin = User::factory()->create([
            'is_super_user' => true,
        ]);

        $response = $this->actingAs($admin)
            ->from(route('domains.show', $domain))
            ->post(route('domains.license.store', $domain), [
                'max_usages' => 5,
                'expires_at' => now()->addMonth()->toDateString(),
            ]);

        $response->assertRedirect(route('domains.show', $domain));
        $this->assertSame(1, $domain->licenses()->count());
    }
}
