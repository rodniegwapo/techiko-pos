<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Domain;
use App\Models\User;
use App\Services\UserHierarchyService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {
        // Middleware is handled at route level
    }

    /**
     * Display a listing of users for the domain.
     */
    public function index(Request $request, Domain $domain = null)
    {
        $currentUser = auth()->user();
        
        $users = User::query()
            ->with(['roles', 'supervisor'])
            ->when($domain, function ($query) use ($domain) {
                return $query->where('domain', $domain->name_slug);
            })
            ->when($request->search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->when($request->role, function ($query, $role) {
                return $query->whereHas('roles', function ($q) use ($role) {
                    $q->where('name', $role);
                });
            })
            ->latest()
            ->paginate(15);

        $roles = $this->userService->getManageableRoles($currentUser);

        return Inertia::render('Users/Index', [
            'items' => UserResource::collection($users),
            'roles' => $roles,
            'hierarchy' => UserHierarchyService::getRoleHierarchy(),
            'currentDomain' => $domain,
            'isGlobalView' => !$domain,
        ]);
    }

    /**
     * Store a newly created user for the domain.
     */
    public function store(Request $request, Domain $domain = null)
    {
        $this->authorize('create', User::class);

        $currentUser = auth()->user();
        $rules = $this->userService->getCreationValidationRules($currentUser);
        $validated = $request->validate($rules);

        if ($domain) {
            $validated['domain'] = $domain->name_slug;
        }
        $user = $this->userService->createUser($validated, $currentUser);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'user' => new UserResource($user->load(['roles', 'supervisor']))
        ], 201);
    }

    /**
     * Update the specified user for the domain.
     */
    public function update(Request $request, Domain $domain, User $user)
    {
        // Ensure user belongs to this domain
        if ($user->domain !== $domain->name_slug) {
            abort(403, 'User does not belong to this domain');
        }

        $this->authorize('update', $user);

        $currentUser = auth()->user();
        $rules = $this->userService->getUpdateValidationRules($currentUser, $user);
        $validated = $request->validate($rules);

        $user = $this->userService->updateUser($user, $validated, $currentUser);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => new UserResource($user->load(['roles', 'supervisor']))
        ]);
    }

    /**
     * Remove the specified user from the domain.
     */
    public function destroy(Domain $domain, User $user)
    {
        // Ensure user belongs to this domain
        if ($user->domain !== $domain->name_slug) {
            abort(403, 'User does not belong to this domain');
        }

        $this->authorize('delete', $user);

        // Prevent deleting the current user
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account'
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }
}
