<?php

namespace App\Services\Ai;

use App\Models\Domain;
use App\Models\User;
use App\Support\RoutePermission;

/**
 * The pages of the app the assistant may point a user to (config/assistant_map.php): only the
 * ones their role can open and their organization has.
 */
class AppMap
{
    /** @return list<array{route: string, path: string, about: string, feature?: string}> */
    public function pagesFor(User $user, Domain $domain): array
    {
        return array_values(array_filter(
            config('assistant_map', []),
            fn (array $page) => (! isset($page['feature']) || $domain->hasFeature($page['feature']))
                && RoutePermission::allows($user, $page['route']),
        ));
    }

    /** One line per page, "Sidebar → Page: what it is for", for the assistant's instructions. */
    public function forUser(User $user, Domain $domain): string
    {
        return implode("\n", array_map(
            fn (array $page) => '- '.$page['path'].': '.$page['about'],
            $this->pagesFor($user, $domain),
        ));
    }

    /** Where a route is in the sidebar ("Inventory → Movements"), or null for a page not in the map. */
    public function pathOf(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }
        $routeName = str_starts_with($routeName, 'domains.') ? substr($routeName, strlen('domains.')) : $routeName;

        foreach (config('assistant_map', []) as $page) {
            if ($page['route'] === $routeName) {
                return $page['path'];
            }
        }

        return null;
    }
}
