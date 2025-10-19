<?php

namespace App\Services;

use App\Models\User;
use App\Services\UserHierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserService
{
    /**
     * Get filtered and paginated users
     */
    public function getFilteredUsers(Request $request, User $currentUser)
    {
        $query = User::with(['roles', 'supervisor']);

        // Apply search using Searchable trait
        if ($request->input('search')) {
            $query->search($request->input('search'));
        }

        // Apply role filter
        if ($request->input('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->input('role'));
            });
        }

        // Apply hierarchy-based filtering
        $query = $this->applyHierarchyFiltering($query, $currentUser);

        return $query->latest()->paginate(15);
    }

    /**
     * Get users for hierarchy view
     */
    public function getHierarchyUsers(User $currentUser)
    {
        $query = User::with(['roles', 'supervisor', 'subordinates']);

        // Apply hierarchy-based filtering
        $query = $this->applyHierarchyFiltering($query, $currentUser);

        return $query->orderBy('name')->get();
    }

    /**
     * Apply hierarchy-based filtering to query
     */
    private function applyHierarchyFiltering($query, User $currentUser)
    {
        // Super users can see everyone
        if ($currentUser->isSuperUser()) {
            return $query;
        }

        // Get viewable roles based on hierarchy
        $viewableRoles = UserHierarchyService::getViewableRoles($currentUser);
        
        $query->whereHas('roles', function ($q) use ($viewableRoles) {
            $q->whereIn('name', $viewableRoles);
        })->where('is_super_user', false);

        // Supervisor can only see their direct subordinates
        if ($currentUser->hasRole('supervisor')) {
            $query->where('supervisor_id', $currentUser->id);
        }

        return $query;
    }

    /**
     * Get manageable roles for current user
     */
    public function getManageableRoles(User $currentUser)
    {
        $manageableRoles = $currentUser->isSuperUser() 
            ? array_keys(UserHierarchyService::getRoleHierarchy())
            : UserHierarchyService::getManageableRoles($currentUser);
            
        return Role::whereIn('name', $manageableRoles)->get(['id', 'name']);
    }

    /**
     * Get available roles for user creation
     */
    public function getAvailableRolesForCreation(User $currentUser)
    {
        return Role::query()
            ->when($currentUser->hasRole('manager'), function ($query) {
                // Manager can only create cashier and supervisor roles
                return $query->whereIn('name', ['cashier', 'supervisor']);
            })
            ->when($currentUser->hasRole('admin') && !$currentUser->hasRole('super admin'), function ($query) {
                return $query->where('name', '!=', 'super admin');
            })
            ->pluck('id');
    }

    /**
     * Create a new user
     */
    public function createUser(array $data, User $currentUser)
    {
        $availableRoles = $this->getAvailableRolesForCreation($currentUser);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'supervisor_id' => $data['supervisor_id'] ?? null,
        ]);

        // Assign role with correct guard
        $role = Role::findById($data['role_id'], 'web');
        $user->assignRole($role);

        return $user->load('roles');
    }

    /**
     * Update user data
     */
    public function updateUser(User $user, array $data, User $currentUser)
    {
        $canUpdateSensitive = $this->canUpdateSensitiveFields($currentUser, $user);

        $updateData = [];
        
        if ($canUpdateSensitive) {
            $updateData = [
                'name' => $data['name'],
                'email' => $data['email'],
                'supervisor_id' => $data['supervisor_id'] ?? null,
            ];

            if (!empty($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }
        } else {
            // Manager can only update non-sensitive fields
            if (isset($data['status'])) {
                $updateData['status'] = $data['status'];
            }
        }

        $user->update($updateData);

        // Update role only if user has permission
        if ($canUpdateSensitive && isset($data['role_id'])) {
            $role = Role::findById($data['role_id'], 'web');
            $user->syncRoles([$role]);
        }

        return $user->fresh()->load('roles');
    }

    /**
     * Check if current user can update sensitive fields
     */
    private function canUpdateSensitiveFields(User $currentUser, User $targetUser): bool
    {
        return $currentUser->isSuperUser() || 
               ($currentUser->hasAnyRole(['super admin', 'admin']) && 
                ($currentUser->hasRole('super admin') || !$targetUser->hasRole('super admin')));
    }

    /**
     * Toggle user status
     */
    public function toggleUserStatus(User $user, User $currentUser)
    {
        // Only super admin can toggle status of super admin users
        if ($user->hasRole('super admin') && !$currentUser->hasRole('super admin')) {
            throw new \Exception('Unauthorized to modify super admin users');
        }

        // Cannot toggle your own status
        if ($user->id === $currentUser->id) {
            throw new \Exception('You cannot modify your own status');
        }

        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        return [
            'user' => $user->fresh(),
            'action' => $newStatus === 'active' ? 'activated' : 'deactivated'
        ];
    }

    /**
     * Get validation rules for user creation
     */
    public function getCreationValidationRules(User $currentUser)
    {
        $availableRoles = $this->getAvailableRolesForCreation($currentUser);

        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => ['required', 'exists:roles,id', function ($attribute, $value, $fail) use ($availableRoles) {
                if (!$availableRoles->contains($value)) {
                    $fail('You are not authorized to assign this role.');
                }
            }],
            'supervisor_id' => 'nullable|exists:users,id'
        ];
    }

    /**
     * Get validation rules for user update
     */
    public function getUpdateValidationRules(User $currentUser, User $targetUser)
    {
        $canUpdateSensitive = $this->canUpdateSensitiveFields($currentUser, $targetUser);
        
        if ($canUpdateSensitive) {
            $availableRoles = Role::query()
                ->when($currentUser->hasRole('admin') && !$currentUser->hasRole('super admin'), function ($query) {
                    return $query->where('name', '!=', 'super admin');
                })
                ->pluck('id');

            return [
                'name' => 'required|string|max:255',
                'email' => ['required', 'email', Rule::unique('users')->ignore($targetUser->id)],
                'password' => 'nullable|string|min:8|confirmed',
                'role_id' => ['required', 'exists:roles,id', function ($attribute, $value, $fail) use ($availableRoles) {
                    if (!$availableRoles->contains($value)) {
                        $fail('You are not authorized to assign this role.');
                    }
                }],
                'supervisor_id' => 'nullable|exists:users,id'
            ];
        }

        return [
            'status' => 'required|in:active,inactive',
        ];
    }
}
