<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
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
     * Display user management page
     */
    public function index(Request $request)
    {
        $currentUser = auth()->user();
        
        $users = $this->userService->getFilteredUsers($request, $currentUser);
        $roles = $this->userService->getManageableRoles($currentUser);

        return Inertia::render('Users/Index', [
            'items' => UserResource::collection($users),
            'roles' => $roles,
            'hierarchy' => UserHierarchyService::getRoleHierarchy(),
        ]);
    }

    /**
     * Store a new user
     */
    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $currentUser = auth()->user();
        $rules = $this->userService->getCreationValidationRules($currentUser);
        $validated = $request->validate($rules);

        $user = $this->userService->createUser($validated, $currentUser);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'user' => $user
        ], 201);
    }

    /**
     * Update user
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $currentUser = auth()->user();
        $rules = $this->userService->getUpdateValidationRules($currentUser, $user);
        $validated = $request->validate($rules);

        $updatedUser = $this->userService->updateUser($user, $validated, $currentUser);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => $updatedUser
        ]);
    }

    /**
     * Delete user
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account'
            ], 400);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        $user->load(['roles', 'supervisor', 'subordinates']);
        
        return Inertia::render('Users/Show', [
            'user' => $user,
        ]);
    }

    /**
     * Toggle user status (active/inactive)
     */
    public function toggleStatus(User $user)
    {
        $currentUser = auth()->user();

        try {
            $result = $this->userService->toggleUserStatus($user, $currentUser);
            
            return response()->json([
                'success' => true,
                'message' => "User {$result['action']} successfully",
                'user' => $result['user']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get available roles for user creation/editing
     */
    public function getRoles()
    {
        $roles = $this->userService->getManageableRoles(auth()->user());
        return response()->json($roles);
    }

    /**
     * Display the user hierarchy page
     */
    public function hierarchy(Request $request)
    {
        $currentUser = auth()->user();
        $users = $this->userService->getHierarchyUsers($currentUser);
        $hierarchy = UserHierarchyService::getRoleHierarchy();

        return Inertia::render('Users/Hierarchy', [
            'users' => $users,
            'hierarchy' => $hierarchy,
        ]);
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user)
    {
        $user->load(['roles']);
        $currentUser = auth()->user();
        $roles = $this->userService->getManageableRoles($currentUser);
        
        return Inertia::render('Users/Edit', [
            'user' => $user,
            'roles' => $roles,
        ]);
    }
}