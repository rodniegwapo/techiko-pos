<?php

namespace Tests\Feature;

use App\Services\DomainService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** The Philippines for now: a business is on Manila time; other timezones are coming soon. */
class TimezonePhilippinesOnlyTest extends TestCase
{
    public function test_the_app_clock_is_manila(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame('Asia/Manila', now()->getTimezone()->getName());
    }

    public function test_only_manila_is_accepted_for_a_business(): void
    {
        $rule = ['timezone' => app(DomainService::class)->getCreationValidationRules()['timezone']];

        $this->assertTrue(Validator::make(['timezone' => 'Asia/Manila'], $rule)->passes());
        $this->assertTrue(Validator::make(['timezone' => 'Asia/Tokyo'], $rule)->fails(), 'others are coming soon');
    }
}
