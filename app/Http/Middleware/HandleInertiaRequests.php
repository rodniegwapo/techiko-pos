<?php

namespace App\Http\Middleware;

use App\Helpers;
use App\Http\Resources\AuthUserResource;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Services\Ai\GuideLibrary;
use App\Services\ImpersonationService;
use App\Support\LocationAreas;
use App\Support\RoutePermission;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
    public function version(Request $request): ?string
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
        $impersonationService = app(ImpersonationService::class);

        $user = $request->user();
        $currentDomain = $this->getCurrentDomain($request);

        return [
            ...parent::share($request),
            'appUrl' => rtrim((string) config('app.url'), '/'),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                // Shown once by the layout: why the user was sent away from a page (e.g. a warehouse's sales screen).
                'notice' => $request->session()->get('notice'),
            ],
            'auth' => [
                'user' => $user ? AuthUserResource::make($user->load('roles', 'permissions')) : null,
            ],
            'myConversation' => $this->myConversationPayload($user),
            'inquiryUnreadCount' => $user?->isSuperUser()
                ? Conversation::unreadInboxConversationsForStaffCount()
                : 0,
            'currentDomain' => $currentDomain,
            // The sidebar and route helpers switch into an organization only on its own pages.
            'inOrganization' => $this->inOrganization($request),
            // For the "subscription ends soon" banner and the locked (greyed-out) sidebar, on the organization's pages.
            // A VIP organization has no end date to be warned about and is never locked.
            'subscriptionStatus' => $currentDomain && $this->inOrganization($request) ? [
                'vip' => $currentDomain->isVip(),
                'locked' => $currentDomain->isLocked(),
                'ends_at' => $currentDomain->isVip() ? null : $currentDomain->subscription_ends_at?->toDateString(),
                'ends_in_days' => $currentDomain->isVip() ? null : $currentDomain->subscriptionEndsInDays(),
            ] : null,
            // Paid features this organization has (its plan, or given by a super admin); the menu locks what's off.
            'domainFeatures' => $currentDomain?->features() ?? array_fill_keys(Domain::FEATURES, false),
            'currentLocation' => $currentLocation = $this->getCurrentLocation($request),
            // Parts of the app this location doesn't show (sidebar); EnsureLocationArea turns the pages away.
            'locationAreas' => [
                'hidden' => LocationAreas::hiddenAt($currentLocation instanceof InventoryLocation ? $currentLocation : null, $request->user()),
                'prefixes' => collect(config('location_areas.areas', []))
                    ->map(fn ($prefixes) => array_map(fn ($p) => Str::after($p, 'domains.'), $prefixes)),
            ],
            // How-to guides this user may follow, { key: title }, for the pages' "?" help.
            'helpGuides' => fn () => $request->user() && $currentDomain
                ? array_map(fn (array $g) => $g['title'], app(GuideLibrary::class)->allowedFor($request->user(), $currentDomain))
                : [],
            // Pages that open with another page's permission (RoutePermission::ALIASES).
            'routePermissionAliases' => RoutePermission::ALIASES,
            'availableLocations' => $this->getAvailableLocations($request),
            'default_store' => $request->user() ? $this->getDefaultStore($request) : null,
            'impersonation' => $impersonationService->getImpersonationData(),
            'features' => config('features'),
        ];
    }

    /**
     * Get the current domain based on route or user
     */
    private function getCurrentDomain(Request $request)
    {
        // Get domain from route parameter, only on the organization's own pages (routes/domains.php).
        // Super admins' Domains pages (/domains/{domain}, /domains/{domain}/edit) only look at an organization.
        $domain = $this->inOrganization($request) ? $request->route('domain') : null;
        $domainSlug = data_get($domain, 'name_slug') ?? $domain;

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

    /** Whether this is one of an organization's own pages (/domains/{domain}/...), not the Domains admin. */
    private function inOrganization(Request $request): bool
    {
        return str_starts_with(ltrim((string) $request->route()?->getPrefix(), '/'), 'domains/{domain');
    }

    /**
     * Get the current location based on user's role and preferences
     */
    private function getCurrentLocation(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $domain = $this->getCurrentDomain($request);
        if (! $domain) {
            return null;
        }

        // Inertia shares props before route middleware runs, so RoleBasedAccessControl hasn't
        // pinned location_id yet. Apply the same restriction here, or a restricted user asking for
        // ?location_id=<other store> would be shown that store while seeing their own store's data.
        $locationId = $user->hasLocationRestriction()
            ? $user->getEffectiveLocationId($request->input('location_id'))
            : $request->input('location_id');

        return Helpers::getActiveLocation($domain, $locationId);
    }

    /**
     * Get available locations for the current domain
     */
    private function getAvailableLocations(Request $request)
    {
        $domain = $this->getCurrentDomain($request);
        if (! $domain) {
            return collect();
        }

        return InventoryLocation::active()->forDomain($domain->name_slug)
            // Cashiers work at stores only (see Helpers::getActiveLocation).
            ->when($request->user()?->isCashierOrBelow(), fn ($q) => $q->where(
                fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'warehouse')
            ))
            ->get();
    }

    /**
     * Get the default store for the current domain
     */
    private function getDefaultStore(Request $request)
    {
        $domain = $this->getCurrentDomain($request);
        if (! $domain) {
            return null;
        }

        return InventoryLocation::getDefault($domain->name_slug);
    }

    /**
     * @return array{id: int|null, messages: array<int, array<string, mixed>>}
     */
    private function myConversationPayload(?Authenticatable $user): array
    {
        if ($user === null) {
            return ['id' => null, 'messages' => []];
        }

        $conversation = Conversation::query()->where('user_id', $user->id)->first();
        if (! $conversation) {
            return ['id' => null, 'messages' => []];
        }

        $customerId = (int) $conversation->user_id;
        $messages = $conversation->messages()
            ->with('author')
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get()
            ->reverse()
            ->values();

        $mapped = $messages->map(function (ConversationMessage $m) use ($customerId) {
            return [
                'id' => $m->id,
                'body' => $m->body,
                'author_user_id' => $m->author_user_id,
                'is_from_customer' => (int) $m->author_user_id === $customerId,
                'created_at' => $m->created_at?->toIso8601String(),
                'author' => $m->author ? [
                    'id' => $m->author->id,
                    'name' => $m->author->name,
                    'email' => $m->author->email,
                ] : null,
            ];
        })->all();

        return [
            'id' => (int) $conversation->id,
            'messages' => $mapped,
        ];
    }
}
