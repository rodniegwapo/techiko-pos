<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
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
        // Super admin, admin, manager, and supervisor can access user management
        // (read-only for managers and supervisors)
        $this->middleware(['auth', 'role:super admin|admin|manager|supervisor']);
    }

    /**
     * Display user management page
     */
    public function index(Request $request)
    {
        $currentUser = auth()->user();
        $currentUserRoles = $currentUser->roles->pluck('name')->map(fn($role) => strtolower($role))->toArray();
        
        $users = User::with(['roles', 'supervisor'])
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
            // Filter users based on current user's role hierarchy
            ->when($currentUser->hasRole('manager'), function ($query) {
                // Manager can only see cashiers and supervisors
                return $query->whereHas('roles', function ($q) {
                    $q->whereIn('name', ['cashier', 'supervisor']);
                });
            })
            ->when($currentUser->hasRole('supervisor'), function ($query) use ($currentUser) {
                // Supervisor can only see cashiers assigned to them
                return $query->whereHas('roles', function ($q) {
                    $q->where('name', 'cashier');
                })->where('supervisor_id', $currentUser->id);
            })
            ->when($currentUser->hasRole('admin') && !$currentUser->hasRole('super admin'), function ($query) {
                // Admin can see everyone except super admin
                return $query->whereDoesntHave('roles', function ($q) {
                    $q->where('name', 'super admin');
                });
            })
            ->latest()
            ->paginate(15);

        // Filter available roles based on current user's permissions
        $roles = Role::query()
            ->when($currentUser->hasRole('manager'), function ($query) {
                // Manager can only assign cashier and supervisor roles
                return $query->whereIn('name', ['cashier', 'supervisor']);
            })
            ->when($currentUser->hasRole('supervisor'), function ($query) {
                // Supervisor cannot create users, so no roles needed
                return $query->whereRaw('1 = 0'); // Return empty result
            })
            ->when($currentUser->hasRole('admin') && !$currentUser->hasRole('super admin'), function ($query) {
                // Admin can assign all roles except super admin
                return $query->where('name', '!=', 'super admin');
            })
            ->get(['id', 'name']);

        return Inertia::render('Users/Index', [
            'items' => UserResource::collection($users),
            'roles' => $roles->values()
        ]);
    }

    /**
     * Store a new user
     */
    public function store(Request $request)
    {
        // Super Admin, Admin, and Manager can create users
        $this->authorize('create', User::class);

        $currentUser = auth()->user();
        
        // Get available roles for the current user
        $availableRoles = Role::query()
            ->when($currentUser->hasRole('manager'), function ($query) {
                // Manager can only create cashier and supervisor roles
                return $query->whereIn('name', ['cashier', 'supervisor']);
            })
            ->when($currentUser->hasRole('admin') && !$currentUser->hasRole('super admin'), function ($query) {
                return $query->where('name', '!=', 'super admin');
            })
            ->pluck('id');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => ['required', 'exists:roles,id', function ($attribute, $value, $fail) use ($availableRoles) {
                if (!$availableRoles->contains($value)) {
                    $fail('You are not authorized to assign this role.');
                }
            }]
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // Assign role with correct guard
        $role = Role::findById($validated['role_id'], 'web');
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
        // Check if user can update this model
        $this->authorize('update', $user);

        $currentUser = auth()->user();
        
        // Determine if current user can update sensitive fields
        $canUpdateSensitive = $currentUser->hasAnyRole(['super admin', 'admin']) && 
                             ($currentUser->hasRole('super admin') || !$user->hasRole('super admin'));

        // Build validation rules based on permissions
        $rules = [];
        
        if ($canUpdateSensitive) {
            // Admin/Super Admin can update all fields
            $rules = [
                'name' => 'required|string|max:255',
                'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
                'password' => 'nullable|string|min:8|confirmed',
                'role_id' => ['required', 'exists:roles,id']
            ];
            
            // Get available roles for role validation
            $availableRoles = Role::query()
                ->when($currentUser->hasRole('admin') && !$currentUser->hasRole('super admin'), function ($query) {
                    return $query->where('name', '!=', 'super admin');
                })
                ->pluck('id');
                
            $rules['role_id'][] = function ($attribute, $value, $fail) use ($availableRoles) {
                if (!$availableRoles->contains($value)) {
                    $fail('You are not authorized to assign this role.');
                }
            };
        } else {
            // Manager can only update non-sensitive fields
            $rules = [
                'status' => 'required|in:active,inactive',
                // Add other non-sensitive fields as needed
            ];
        }

        $validated = $request->validate($rules);

        // Build update data based on permissions
        $updateData = [];
        
        if ($canUpdateSensitive) {
            // Update sensitive fields for admin/super admin
            $updateData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
            ];

            if (!empty($validated['password'])) {
                $updateData['password'] = Hash::make($validated['password']);
            }
        } else {
            // Update only non-sensitive fields for manager
            if (isset($validated['status'])) {
                $updateData['status'] = $validated['status'];
            }
        }

        $user->update($updateData);

        // Update role only if user has permission
        if ($canUpdateSensitive && isset($validated['role_id'])) {
            $role = Role::findById($validated['role_id'], 'web');
            $user->syncRoles([$role]);
        }

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