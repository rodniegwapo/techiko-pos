<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct()
    {
        // Only super admin and admin can access user management
        $this->middleware(['auth', 'role:super admin|admin']);
    }

    /**
     * Display user management page
     */
    public function index(Request $request)
    {
        $currentUser = auth()->user();
        $currentUserRoles = $currentUser->roles->pluck('name')->map(fn($role) => strtolower($role))->toArray();
        
        $users = User::with('roles')
            ->when($request->input('search'), function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->input('role'), function ($query, $role) {
                return $query->whereHas('roles', function ($q) use ($role) {
                    $q->where('name', $role);
                });
            })
            // Filter users based on current user's role
            ->when(!in_array('super admin', $currentUserRoles), function ($query) use ($currentUserRoles) {
                // Admin cannot see Super Admin users
                if (in_array('admin', $currentUserRoles)) {
                    $query->whereDoesntHave('roles', function ($q) {
                        $q->where('name', 'super admin');
                    });
                }
                // Manager cannot see Super Admin and Admin users
                elseif (in_array('manager', $currentUserRoles)) {
                    $query->whereDoesntHave('roles', function ($q) {
                        $q->whereIn('name', ['super admin', 'admin']);
                    });
                }
            })
            ->latest()
            ->paginate(15);

        // Filter available roles based on current user's permissions
        $roles = Role::all(['id', 'name']);
        if (!in_array('super admin', $currentUserRoles)) {
            if (in_array('admin', $currentUserRoles)) {
                $roles = $roles->where('name', '!=', 'super admin');
            } elseif (in_array('manager', $currentUserRoles)) {
                $roles = $roles->whereNotIn('name', ['super admin', 'admin']);
            }
        }

        return Inertia::render('Users/Index', [
            'items' => $users,
            'roles' => $roles->values()
        ]);
    }

    /**
     * Store a new user
     */
    public function store(Request $request)
    {
        // Only Super Admin and Admin can create users
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id'
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // Assign role
        $role = Role::findById($validated['role_id']);
        $user->assignRole($role);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'user' => $user->load('roles')
        ], 201);
    }

    /**
     * Update user
     */
    public function update(Request $request, User $user)
    {
        // Only Super Admin and Admin can edit users
        $this->authorize('update', $user);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id'
        ]);

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        // Update role
        $role = Role::findById($validated['role_id']);
        $user->syncRoles([$role]);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => $user->fresh()->load('roles')
        ]);
    }

    /**
     * Delete user
     */
    public function destroy(User $user)
    {
        // Only Super Admin can delete users
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
     * Get user details
     */
    public function show(User $user)
    {
        return response()->json([
            'user' => $user->load('roles', 'permissions')
        ]);
    }

    /**
     * Toggle user status (active/inactive)
     */
    public function toggleStatus(User $user)
    {
        // Only super admin can toggle status of super admin users
        if ($user->hasRole('super admin') && !auth()->user()->hasRole('super admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to modify super admin users'
            ], 403);
        }

        // Cannot toggle your own status
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot modify your own status'
            ], 400);
        }

        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        $action = $newStatus === 'active' ? 'activated' : 'deactivated';
        
        return response()->json([
            'success' => true,
            'message' => "User {$action} successfully",
            'user' => $user->fresh()
        ]);
    }

    /**
     * Get available roles for user creation/editing
     */
    public function getRoles()
    {
        $roles = Role::all(['id', 'name']);
        
        // If user is admin (not super admin), exclude super admin role
        if (!auth()->user()->hasRole('super admin')) {
            $roles = $roles->where('name', '!=', 'super admin');
        }

        return response()->json($roles);
    }
}