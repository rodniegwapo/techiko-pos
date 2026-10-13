<?php

namespace App\Services\Ai;

use App\Models\Domain;
use App\Models\User;
use App\Support\RouteFeature;
use App\Support\RoutePermission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The how-to guides the AI assistant teaches from (resources/ai/guides/*.md). Each guide starts
 * with a small header:
 *
 *   key: add-product
 *   title: Add a product
 *   page: products.create              (the page to open, a route name without "domains.")
 *   requires: products.create, products.store   (every permission the task needs)
 *   keywords: add product, new item…
 *
 * A user only sees guides for tasks their role may do.
 */
class GuideLibrary
{
    /** @return array<string, array{key: string, title: string, page: ?string, requires: list<string>, keywords: list<string>, steps: string}> */
    public function all(): array
    {
        $directory = resource_path('ai/guides');

        return Cache::remember('assistant-guides:'.$this->fingerprint($directory), now()->addDay(), function () use ($directory) {
            $guides = [];
            foreach (File::glob($directory.'/*.md') as $path) {
                $guide = $this->parse((string) file_get_contents($path));
                if ($guide !== null) {
                    $guides[$guide['key']] = $guide;
                }
            }
            ksort($guides);

            return $guides;
        });
    }

    /** @return array<string, array{key: string, title: string, page: ?string, requires: list<string>, keywords: list<string>, steps: string}> */
    public function allowedFor(User $user, ?Domain $domain = null): array
    {
        return array_filter($this->all(), fn (array $guide) => $this->allows($user, $guide, $domain));
    }

    /**
     * The user's role has every permission the task needs, and (given the business) the business
     * has the features its pages need, e.g. Finance for the expense guides.
     */
    public function allows(User $user, array $guide, ?Domain $domain = null): bool
    {
        foreach ($guide['requires'] as $routeName) {
            if (! RoutePermission::allows($user, $routeName)) {
                return false;
            }
            if ($domain && ! RouteFeature::allows($domain, $routeName)) {
                return false;
            }
        }

        return true;
    }

    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The guides that best match the words of a question, best first.
     *
     * @return list<array{key: string, title: string}>
     */
    public function search(User $user, string $query, int $limit = 5, ?Domain $domain = null): array
    {
        $scored = [];
        foreach ($this->allowedFor($user, $domain) as $guide) {
            $score = $this->score($guide, $query);
            if ($score > 0) {
                $scored[] = ['score' => $score, 'key' => $guide['key'], 'title' => $guide['title']];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($g) => ['key' => $g['key'], 'title' => $g['title']], array_slice($scored, 0, $limit));
    }

    /** How often the words of a question appear in a guide's title and keywords (0 = no match). */
    public function score(array $guide, string $query): int
    {
        $haystack = Str::lower($guide['title'].' '.implode(' ', $guide['keywords']));
        $score = 0;
        foreach (preg_split('/[^\p{L}\p{N}]+/u', Str::lower($query)) ?: [] as $word) {
            if (mb_strlen($word) > 2) {
                $score += substr_count($haystack, $word);
            }
        }

        return $score;
    }

    /** The address of a guide's page in this business, or null when there is no such page. */
    public function pageUrl(array $guide, string $domainSlug): ?string
    {
        $page = $guide['page'];
        if ($page === null) {
            return null;
        }

        if (Route::has('domains.'.$page)) {
            return route('domains.'.$page, ['domain' => $domainSlug]);
        }

        return Route::has($page) ? route($page) : null;
    }

    private function parse(string $contents): ?array
    {
        if (! preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $contents, $match)) {
            return null;
        }

        $header = [];
        foreach (preg_split('/\R/', $match[1]) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $header[trim($name)] = trim($value);
            }
        }

        if (($header['key'] ?? '') === '' || ($header['title'] ?? '') === '') {
            return null;
        }

        $list = fn (string $name) => array_values(array_filter(array_map('trim', explode(',', $header[$name] ?? ''))));

        return [
            'key' => $header['key'],
            'title' => $header['title'],
            'page' => ($header['page'] ?? '') !== '' ? $header['page'] : null,
            'requires' => $list('requires'),
            'keywords' => $list('keywords'),
            'steps' => trim($match[2]),
        ];
    }

    /** Changes whenever a guide is added, removed or edited, so the cache never serves old steps. */
    private function fingerprint(string $directory): string
    {
        $files = File::glob($directory.'/*.md');

        return md5(implode('|', array_map(fn ($path) => $path.':'.filemtime($path), $files)));
    }
}
