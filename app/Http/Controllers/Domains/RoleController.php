<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Domain;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use App\Models\Permission;

class RoleController extends Controller
{
    /**
     * Display a listing of roles for the domain.
     */
    public function index(Request $request, Domain $domain = null)
    {
        $currentUser = auth()->user();

        $roles = Role::query()
            ->with('permissions')
            ->where('name', '!=', 'super admin')
            ->when($domain, function ($query) use ($domain) {
                return $query->where('domain', $domain->name_slug);
            })
            ->when($request->search, fn($q, $s) => $q->search($s))
            ->orderBy('name')
            ->paginate(10);

        $permissions = $this->getPermissionsGroupedByModule();

        return Inertia::render('Roles/Index', [
            'roles' => RoleResource::collection($roles),
            'permissions' => $permissions,
            'canCreate' => $currentUser->isSuperUser() || $currentUser->hasAnyPermission(['roles.create', 'roles.store']),
            'canEdit' => $currentUser->isSuperUser() || $currentUser->hasAnyPermission(['roles.edit', 'roles.update']),
            'canDelete' => $currentUser->isSuperUser() || $currentUser->can('roles.destroy'),
            'currentDomain' => $domain,
            'isGlobalView' => !$domain,
        ]);
    }

    /**
     * Store a newly created role for the domain.
     */
    public function store(Request $request, Domain $domain = null)
    {
        $this->authorize('create', new Role());

        $validated = $this->validateRole($request);

        try {
            $roleData = [
                'name' => $validated['name'],
                'guard_name' => 'web',
                'level' => $validated['level'],
                'description' => $validated['description'],
            ];

            if ($domain) {
                $roleData['domain'] = $domain->name_slug;
            }

            $role = Role::create($roleData);

            // Assign permissions if provided
            $this->syncRolePermissions($role, $validated['permissions'] ?? []);

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'role' => new RoleResource($role->load('permissions'))
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create role: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * Update the specified role for the domain.
     */
    public function update(Request $request, Domain $domain, Role $role)
    {
        // Ensure role belongs to this domain
        if ($role->domain !== $domain->name_slug) {
            abort(403, 'Role does not belong to this domain');
        }

        $this->authorize('update', $role);

        $validated = $this->validateRole($request, $role);

        try {
            $role->update([
                'name' => $validated['name'],
                'level' => $validated['level'],
                'description' => $validated['description'],
            ]);

            // Sync permissions
            $this->syncRolePermissions($role, $validated['permissions'] ?? []);

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'role' => new RoleResource($role->load('permissions'))
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update role: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * Remove the specified role from the domain.
     */
    public function destroy(Domain $domain, Role $role)
    {
        // Ensure role belongs to this domain
        if ($role->domain !== $domain->name_slug) {
            abort(403, 'Role does not belong to this domain');
        }

        $this->authorize('delete', $role);

        // Prevent deleting super admin role
        if ($role->name === 'super admin') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete super admin role'
            ], 422);
        }

        // Check if role has users
        if ($role->users()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete role with assigned users'
            ], 422);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully'
        ]);
    }

    /**
     * Validate role data
     */
    private function validateRole(Request $request, Role $role = null)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'level' => 'required|integer|min:1|max:100',
            'description' => 'nullable|string|max:255',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ];

        if ($role) {
            $rules['name'] .= '|unique:roles,name,' . $role->id;
        } else {
            $rules['name'] .= '|unique:roles,name';
        }

        return $request->validate($rules);
    }

    /**
     * Sync role permissions
     */
    private function syncRolePermissions(Role $role, array $permissionIds)
    {
        $permissions = Permission::whereIn('id', $permissionIds)->get();
        $role->syncPermissions($permissions);
    }

    /**
     * Get permissions grouped by module
     */
    private function getPermissionsGroupedByModule()
    {
        return Permission::all()->groupBy('module')->map(function ($permissions) {
            return $permissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->display_name,
                    'description' => $permission->description,
                ];
            });
        });
    }
}
