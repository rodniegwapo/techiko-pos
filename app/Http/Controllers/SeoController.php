<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class SeoController extends Controller
{
    private const SITEMAP_PATHS = [
        ['path' => '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
        ['path' => '/services', 'priority' => '0.8', 'changefreq' => 'monthly'],
        ['path' => '/about', 'priority' => '0.6', 'changefreq' => 'monthly'],
        ['path' => '/contact', 'priority' => '0.6', 'changefreq' => 'monthly'],
        ['path' => '/pricing', 'priority' => '0.6', 'changefreq' => 'monthly'],
    ];

    /**
     * Disallow app areas that should not be indexed; allow marketing and auth pages.
     */
    private const DISALLOW_PREFIXES = [
        '/api/',
        '/dashboard',
        '/profile',
        '/sales',
        '/products',
        '/categories',
        '/discounts',
        '/mandatory-discounts',
        '/loyalty',
        '/customers',
        '/void-logs',
        '/inventory',
        '/domains',
        '/users',
        '/roles',
        '/permissions',
        '/impersonate',
        '/stop-impersonating',
        '/debug-',
    ];

    public function robots(): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $sitemap = $base.'/sitemap.xml';
        $lines = ['User-agent: *', 'Allow: /', ''];
        foreach (self::DISALLOW_PREFIXES as $prefix) {
            $lines[] = 'Disallow: '.$prefix;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$sitemap;
        $lines[] = '';

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function sitemap(): Response
    {
        $base = rtrim((string) config('app.url'), '/');
        $lastmod = Carbon::now()->toIso8601String();
        $urls = [];
        foreach (self::SITEMAP_PATHS as $row) {
            if (
                ($row['path'] ?? '') === '/pricing'
                && ! config('features.marketing_pricing_visible')
            ) {
                continue;
            }
            $loc = $base.$row['path'];
            $urls[] = sprintf(
                '  <url><loc>%s</loc><lastmod>%s</lastmod><changefreq>%s</changefreq><priority>%s</priority></url>',
                e($loc),
                e($lastmod),
                e($row['changefreq']),
                e($row['priority'])
            );
        }
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            ."\n".implode("\n", $urls)."\n".'</urlset>';

        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
