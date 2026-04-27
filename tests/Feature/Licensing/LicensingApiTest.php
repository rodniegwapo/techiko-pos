<?php

namespace Tests\Feature\Licensing;

use Tests\TestCase;

class LicensingApiTest extends TestCase
{
    public function test_licensing_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/licensing/v1/health');

        $response->assertOk();
    }
}
