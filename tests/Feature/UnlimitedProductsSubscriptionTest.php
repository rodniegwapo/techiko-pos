<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Domain;
use App\Models\Product\Product;
use App\Services\DomainSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnlimitedProductsSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_assert_can_create_product_allows_above_free_cap_when_unlimited_feature_on(): void
    {
        config(['features.unlimited_products' => true]);

        $domain = Domain::query()->create([
            'name' => 'Test Org',
            'name_slug' => 'test-org-unlim-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Manila',
            'country_code' => 'PH',
            'currency_code' => 'PHP',
        ]);

        $category = Category::factory()->create(['domain' => $domain->name_slug]);

        Product::factory()->count(15)->create([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
        ]);

        /** @var DomainSubscriptionService $service */
        $service = app(DomainSubscriptionService::class);
        $service->assertCanCreateProduct($domain->fresh());

        $this->expectNotToPerformAssertions();
    }

    public function test_assert_can_create_product_enforces_free_tier_when_unlimited_off(): void
    {
        config(['features.unlimited_products' => false]);

        $domain = Domain::query()->create([
            'name' => 'Test Org',
            'name_slug' => 'test-org-limited-'.Str::lower(Str::random(8)),
            'timezone' => 'Asia/Manila',
            'country_code' => 'PH',
            'currency_code' => 'PHP',
        ]);

        $category = Category::factory()->create(['domain' => $domain->name_slug]);

        Product::factory()->count(10)->create([
            'domain' => $domain->name_slug,
            'category_id' => $category->id,
        ]);

        /** @var DomainSubscriptionService $service */
        $service = app(DomainSubscriptionService::class);

        try {
            $service->assertCanCreateProduct($domain->fresh());
            $this->fail('Expected ValidationException when free tier limit reached.');
        } catch (ValidationException $e) {
            $this->assertTrue($e->validator->errors()->has('plan'));
        }
    }
}
