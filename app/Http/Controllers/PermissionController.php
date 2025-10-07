<?php

namespace App\Http\Controllers;

use App\Http\Resources\PermissionResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    public function __construct()
    {
        // Middleware is handled at route level
    }

    /**
     * Display permission management page
     */
    public function index(Request $request)
    {
        $currentUser = auth()->user();
        
        // Get permissions with usage count
        $permissions = Permission::withCount('roles')
            ->when($request->search, function ($query, $search) {
                return $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate(15);

        // Group permissions by module for display
        $permissionsGrouped = Permission::all()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0];
        });

        return Inertia::render('Permissions/Index', [
            'permissions' => PermissionResource::collection($permissions),
            'permissionsGrouped' => $permissionsGrouped,
            'canCreate' => $currentUser->isSuperUser(),
            'canEdit' => $currentUser->isSuperUser(),
            'canDelete' => $currentUser->isSuperUser(),
        ]);
    }

    /**
     * Show the form for creating a new permission
     */
    public function create()
    {
        $this->authorize('create', new Permission());

        // Get existing modules for suggestions
        $modules = Permission::all()
            ->pluck('name')
            ->map(function ($name) {
                return explode('.', $name)[0];
            })
            ->unique()
            ->sort()
            ->values();

        return Inertia::render('Permissions/Create', [
            'modules' => $modules,
        ]);
    }

    /**
     * Store a newly created permission
     */
    public function store(Request $request)
    {
        $this->authorize('create', new Permission());

        $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name',
            'module' => 'required|string|max:100',
            'action' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $permission = Permission::create([
            'name' => $request->name,
            'guard_name' => 'web',
        ]);

        return redirect()->route('permissions.index')
            ->with('success', 'Permission created successfully.');
    }

    /**
     * Display the specified permission
     */
    public function show(Permission $permission)
    {
        $this->authorize('view', $permission);

        // Get roles that have this permission
        $roles = $permission->roles()->with('users')->get();

        return Inertia::render('Permissions/Show', [
            'permission' => new PermissionResource($permission->load('roles')),
            'roles' => $roles,
        ]);
    }

    /**
     * Show the form for editing the specified permission
     */
    public function edit(Permission $permission)
    {
        $this->authorize('update', $permission);

        // Get existing modules for suggestions
        $modules = Permission::all()
            ->pluck('name')
            ->map(function ($name) {
                return explode('.', $name)[0];
            })
            ->unique()
            ->sort()
            ->values();

        return Inertia::render('Permissions/Edit', [
            'permission' => new PermissionResource($permission),
            'modules' => $modules,
        ]);
    }

    /**
     * Update the specified permission
     */
    public function update(Request $request, Permission $permission)
    {
        $this->authorize('update', $permission);

        $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name,' . $permission->id,
            'module' => 'required|string|max:100',
            'action' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $permission->update([
            'name' => $request->name,
        ]);

        return redirect()->route('permissions.index')
            ->with('success', 'Permission updated successfully.');
    }

    /**
     * Remove the specified permission
     */
    public function destroy(Permission $permission)
    {
        $this->authorize('delete', $permission);

        // Check if permission is in use
        if ($permission->roles()->count() > 0) {
            return redirect()->back()
                ->with('error', 'Cannot delete permission that is assigned to roles.');
        }

        $permission->delete();

        return redirect()->route('permissions.index')
            ->with('success', 'Permission deleted successfully.');
    }

    /**
     * Get all permissions grouped by module (API endpoint)
     */
    public function getGroupedPermissions()
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            return explode('.', $permission->name)[0];
        });

        return response()->json([
            'permissions' => $permissions
        ]);
    }

    /**
     * Bulk delete permissions
     */
    public function bulkDelete(Request $request)
    {
        $this->authorize('delete', new Permission());

        $request->validate([
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $permissions = Permission::whereIn('id', $request->permission_ids)->get();
        
        // Check if any permission is in use
        $inUse = $permissions->filter(function ($permission) {
            return $permission->roles()->count() > 0;
        });

        if ($inUse->count() > 0) {
            return redirect()->back()
                ->with('error', 'Cannot delete permissions that are assigned to roles: ' . $inUse->pluck('name')->join(', '));
        }

        Permission::whereIn('id', $request->permission_ids)->delete();

        return redirect()->route('permissions.index')
            ->with('success', 'Selected permissions deleted successfully.');
    }
}

