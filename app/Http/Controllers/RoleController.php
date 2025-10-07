<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoleResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function __construct()
    {
        // Middleware is handled at route level
    }

    /**
     * Display role management page
     */
    public function index(Request $request)
    {
        $currentUser = auth()->user();
        
        // Get roles with permissions
        $roles = Role::with('permissions')
            ->when($request->search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate(10);

        // Get all permissions grouped by module
        $permissions = $this->getPermissionsGroupedByModule();

        return Inertia::render('Roles/Index', [
            'roles' => RoleResource::collection($roles),
            'permissions' => $permissions,
            'canCreate' => $currentUser->isSuperUser(),
            'canEdit' => $currentUser->isSuperUser(),
            'canDelete' => $currentUser->isSuperUser(),
        ]);
    }

    /**
     * Show the form for creating a new role
     */
    public function create()
    {
        $this->authorize('create', new Role());

        // Get all permissions grouped by module
        $permissions = $this->getPermissionsGroupedByModule();

        return Inertia::render('Roles/Create', [
            'permissions' => $permissions,
        ]);
    }

    /**
     * Store a newly created role
     */
    public function store(Request $request)
    {
        $this->authorize('create', new Role());

        $validated = $this->validateRole($request);

        try {
            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => 'web',
                'level' => $validated['level'],
                'description' => $validated['description'],
            ]);

            // Assign permissions if provided
            $this->syncRolePermissions($role, $validated['permissions'] ?? []);

            // Log the role creation
            Log::info('Role created', [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name,
                'role_id' => $role->id,
                'role_name' => $role->name,
                'permissions_count' => $role->permissions->count(),
                'action' => 'create_role',
                'timestamp' => now()
            ]);

            return redirect()->route('roles.index')->with('success', 'Role created successfully');

        } catch (\Exception $e) {
            Log::error('Role creation failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'data' => $validated
            ]);

            return back()->withErrors(['error' => 'Failed to create role: ' . $e->getMessage()]);
        }
    }

    /**
     * Show the form for editing the specified role
     */
    public function edit(Role $role)
    {
        $this->authorize('update', $role);

        // Get all permissions grouped by module
        $permissions = Permission::all()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0];
        });

        return Inertia::render('Roles/Edit', [
            'role' => new RoleResource($role->load('permissions')),
            'permissions' => $permissions,
        ]);
    }

    /**
     * Update the specified role
     */
    public function update(Request $request, Role $role)
    {
        $this->authorize('update', $role);

        $validated = $this->validateRole($request, $role);

        try {
            $oldPermissions = $role->permissions->pluck('id')->toArray();
            
            $role->update([
                'name' => $validated['name'],
                'level' => $validated['level'],
                'description' => $validated['description'],
            ]);

            // Update permissions
            $this->syncRolePermissions($role, $validated['permissions'] ?? []);

            // Log the role update
            Log::info('Role updated', [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name,
                'role_id' => $role->id,
                'role_name' => $role->name,
                'old_permissions' => $oldPermissions,
                'new_permissions' => $role->permissions->pluck('id')->toArray(),
                'action' => 'update_role',
                'timestamp' => now()
            ]);

            return redirect()->route('roles.index')->with('success', 'Role updated successfully');

        } catch (\Exception $e) {
            Log::error('Role update failed', [
                'user_id' => auth()->id(),
                'role_id' => $role->id,
                'error' => $e->getMessage(),
                'data' => $validated
            ]);

            return back()->withErrors(['error' => 'Failed to update role: ' . $e->getMessage()]);
        }
    }

    /**
     * DRY: Group permissions by module prefix
     */
    private function getPermissionsGroupedByModule()
    {
        return Permission::all()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0];
        });
    }

    /**
     * DRY: Validate role data for store/update
     */
    private function validateRole(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required','string','max:255','unique:roles,name' . ($role ? ',' . $role->id : '')],
            'description' => 'nullable|string|max:500',
            'level' => 'required|integer|min:1|max:99',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);
    }

    /**
     * DRY: Sync role permissions from ids
     */
    private function syncRolePermissions(Role $role, array $permissionIds): void
    {
        // If no permissions provided, clear all
        if (empty($permissionIds)) {
            $role->syncPermissions([]);
            return;
        }

        // Load selected permissions by id
        $selected = Permission::whereIn('id', $permissionIds)->get();

        // Normalize permission names to match route-style actions
        // view->index, create->store, edit->update, delete->destroy
        $normalizedPermissions = $selected->map(function (Permission $perm) {
            $normalizedName = $this->normalizePermissionName($perm->name);
            if ($normalizedName === $perm->name) {
                return $perm; // already normalized
            }
            // Find or create normalized permission
            return Permission::firstOrCreate(['name' => $normalizedName]);
        });

        // Sync using normalized permissions set
        $role->syncPermissions($normalizedPermissions);
    }

    /**
     * Convert legacy permission action names to RESTful route-aligned actions.
     * Example: users.view -> users.index, sales.create -> sales.store
     */
    private function normalizePermissionName(string $permissionName): string
    {
        // Expect format module.action, e.g., users.view
        $parts = explode('.', $permissionName, 2);
        if (count($parts) !== 2) {
            return $permissionName; // unknown format
        }
        [$module, $action] = $parts;
        $map = [
            'view' => 'index',
            'create' => 'store',
            'edit' => 'update',
            'delete' => 'destroy',
        ];
        $normalizedAction = $map[$action] ?? $action;
        return $module . '.' . $normalizedAction;
    }

    /**
     * Remove the specified role
     */
    public function destroy(Role $role)
    {
        $this->authorize('delete', $role);

        // Prevent deletion of system roles
        $systemRoles = ['super admin', 'admin', 'manager', 'supervisor', 'cashier'];
        if (in_array(strtolower($role->name), $systemRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete system roles'
            ], 422);
        }

        // Check if role is assigned to users
        if ($role->users()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete role that is assigned to users'
            ], 422);
        }

        try {
            $roleName = $role->name;
            $roleId = $role->id;
            
            $role->delete();

            // Log the role deletion
            Log::info('Role deleted', [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name,
                'role_id' => $roleId,
                'role_name' => $roleName,
                'action' => 'delete_role',
                'timestamp' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Role deletion failed', [
                'user_id' => auth()->id(),
                'role_id' => $role->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete role: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified role
     */
    public function show(Role $role)
    {
        $this->authorize('view', $role);

        return Inertia::render('Roles/Show', [
            'role' => new RoleResource($role->load('permissions')),
        ]);
    }

    /**
     * Display the permission matrix
     */
    public function permissionMatrix()
    {
        $this->authorize('viewAny', Role::class);

        // Get all roles with permissions
        $roles = Role::with('permissions')->get();

        // Get all permissions grouped by module
        $permissions = Permission::all()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0];
        });

        return Inertia::render('Roles/PermissionMatrix', [
            'roles' => RoleResource::collection($roles),
            'permissions' => $permissions,
        ]);
    }

    /**
     * Get all permissions grouped by module
     */
    public function permissions()
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0];
        });

        return response()->json([
            'permissions' => $permissions
        ]);
    }


}
