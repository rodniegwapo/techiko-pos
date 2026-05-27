<?php

namespace App\Http\Middleware;

use App\Helpers;
use App\Http\Resources\AuthUserResource;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Services\ImpersonationService;
use Illuminate\Contracts\Auth\Authenticatable;
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

        return [
            ...parent::share($request),
            'appUrl' => rtrim((string) config('app.url'), '/'),
            'auth' => [
                'user' => $user ? AuthUserResource::make($user->load('roles', 'permissions')) : null,
            ],
            'myConversation' => $this->myConversationPayload($user),
            'inquiryUnreadCount' => $user?->isSuperUser()
                ? Conversation::unreadInboxConversationsForStaffCount()
                : 0,
            'currentDomain' => $this->getCurrentDomain($request),
            'currentLocation' => $this->getCurrentLocation($request),
            'availableLocations' => $this->getAvailableLocations($request),
            'default_store' => $request->user() ? $this->getDefaultStore($request) : null,
            'impersonation' => $impersonationService->getImpersonationData(),
            'features' => config('features'),
            'db_mode' => fn () => config('runtime_database.enabled')
                ? ($request->attributes->get(SelectRuntimeDatabaseConnection::ATTRIBUTE_DB_MODE, 'online'))
                : 'online',
            'csrf_token' => csrf_token(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                's3_path' => fn () => $request->session()->get('s3_path'),
                's3_url' => fn () => $request->session()->get('s3_url'),
            ],
        ];
    }

    /**
     * Get the current domain based on route or user
     */
    private function getCurrentDomain(Request $request)
    {
        // Get domain from route parameter
        $domain = $request->route('domain');
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

        // Use the centralized helper function
        return Helpers::getActiveLocation($domain, $request->input('location_id'));
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

        return InventoryLocation::active()->forDomain($domain->name_slug)->get();
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
