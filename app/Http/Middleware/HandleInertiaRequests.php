<?php

namespace App\Http\Middleware;

use App\Http\Resources\AuthUserResource;
use App\Models\InventoryLocation;
use App\Models\Domain;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? AuthUserResource::make($request->user()->load('roles', 'permissions')) : null,
            ],
            'currentDomain' => $this->getCurrentDomain($request),
            'currentLocation' => $this->getCurrentLocation($request),
            'availableLocations' => $this->getAvailableLocations($request),
            'default_store' => $request->user() ? InventoryLocation::query()->where('is_default', true)->first() : null,
        ];
    }

    /**
     * Get the current domain based on route or user
     */
    private function getCurrentDomain(Request $request)
    {
        // Get domain from route parameter
        $domain = $request->route('domain');
        $domainSlug = data_get($domain, 'name_slug');

        if ($domainSlug) {
            return Domain::where('name_slug', $domainSlug)->first();
        }

        // Fallback to user's domain
        $user = $request->user();
        if ($user && $user->domain) {
            return Domain::where('name_slug', $user->domain)->first();
        }

        return null;
    }

    /**
     * Get the current location based on user's role and preferences
     */
    private function getCurrentLocation(Request $request)
    {
        $user = $request->user();
        if (!$user) return null;

        $domain = $this->getCurrentDomain($request);
        if (!$domain) return null;

        // Apply role-based location filtering
        $effectiveLocationId = $user->getEffectiveLocationId($request->input('location_id'));

        return $effectiveLocationId
            ? InventoryLocation::forDomain($domain->name_slug)->findOrFail($effectiveLocationId)
            : (InventoryLocation::active()->forDomain($domain->name_slug)->where('is_default', true)->first()
                ?? InventoryLocation::active()->forDomain($domain->name_slug)->first()
                ?? InventoryLocation::getDefault());
    }

    /**
     * Get available locations for the current domain
     */
    private function getAvailableLocations(Request $request)
    {
        $domain = $this->getCurrentDomain($request);
        if (!$domain) return collect();

        return InventoryLocation::active()->forDomain($domain->name_slug)->get();
    }
}
