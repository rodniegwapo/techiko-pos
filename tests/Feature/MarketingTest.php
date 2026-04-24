<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingTest extends TestCase
{
    public function test_marketing_home_returns_successful_response(): void
    {
        $this->get('/')->assertOk();
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
