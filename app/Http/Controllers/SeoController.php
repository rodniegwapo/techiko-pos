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
     * Canonical HTTPS base for sitemap.xml <loc>.
     * Uses seo.canonical_url if set; otherwise APP_URL. In production, http:// gets upgraded.
     */
    private function seoAbsoluteBase(): string
    {
        $raw = trim((string) (config('seo.canonical_url') ?: config('app.url')));
        $base = rtrim($raw, '/');

        if (app()->environment('production') && str_starts_with($base, 'http://')) {
            $base = 'https://'.substr($base, strlen('http://'));
        }

        return $base;
    }

    public function sitemap(): Response
    {
        $base = $this->seoAbsoluteBase();
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
