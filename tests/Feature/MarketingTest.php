<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_home_returns_successful_response(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_root_redirects_authenticated_super_user_to_dashboard(): void
    {
        $user = User::factory()->create([
            'is_super_user' => true,
        ]);

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_marketing_pages_return_ok(): void
    {
        foreach (['/services', '/about', '/contact', '/pricing'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_sitemap_xml_is_served(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee('<urlset', false);
        $response->assertSee(config('app.url'), false);
    }

    public function test_robots_txt_is_served_and_references_sitemap(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Sitemap: '.rtrim(config('app.url'), '/').'/sitemap.xml', false);
    }
}
