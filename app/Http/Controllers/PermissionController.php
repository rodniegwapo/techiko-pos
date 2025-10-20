<?php

namespace App\Http\Controllers;

use App\Http\Resources\PermissionResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Permission;
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

        // Get permissions with usage count and module relationship
        $permissions = Permission::with(['module'])
            ->withCount('roles')
            ->when($request->search, fn($q, $s) => $q->search($s))
            ->when($request->module, function ($query, $module) {
                return $query->whereHas('module', function ($q) use ($module) {
                    $q->where('name', $module);
                });
            })
            ->orderBy('name')
            ->paginate(15);

        // Group permissions by module for display
        $permissionsGrouped = Permission::with('module')->get()->groupBy(function ($permission) {
            return $permission->module ? $permission->module->name : 'other';
        });

        return Inertia::render('Permissions/Index', [
            'items' => PermissionResource::collection($permissions),
            'permissionsGrouped' => $permissionsGrouped,
            'canCreate' => $currentUser->isSuperUser(),
            'canEdit' => $currentUser->isSuperUser(),
            'canDelete' => $currentUser->isSuperUser(),
        ]);
    }


    /**
     * Store a newly created permission
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name',
            'route_name' => 'required|string|max:255|unique:permissions,route_name',
            'description' => 'nullable|string|max:500',
            'module' => 'required|string|max:100',
            'action' => 'required|string|max:100',
        ]);

        $permission = Permission::create([
            'name' => $request->name, // Display name
            'route_name' => $request->route_name, // Technical route name
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
        $roles = $permission->roles()->with('users')->get();

        return Inertia::render('Permissions/Show', [
            'permission' => new PermissionResource($permission->load('roles')),
            'roles' => $roles,
        ]);
    }


    /**
     * Update the specified permission
     */
    public function update(Request $request, Permission $permission)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name,' . $permission->id,
            'route_name' => 'required|string|max:255|unique:permissions,route_name,' . $permission->id,
            'description' => 'nullable|string|max:500',
            'module' => 'required|string|max:100',
            'action' => 'required|string|max:100',
        ]);

        $permission->update([
            'name' => $request->name, // Display name
            'route_name' => $request->route_name, // Technical route name
        ]);

        return redirect()->route('permissions.index')
            ->with('success', 'Permission updated successfully.');
    }

    /**
     * Deactivate the specified permission
     */
    public function deactivate(Permission $permission)
    {
        // Check if permission is in use
        if ($permission->roles()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate permission that is assigned to roles.'
            ], 400);
        }

        $permission->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Permission deactivated successfully.'
        ]);
    }

    /**
     * Activate the specified permission
     */
    public function activate(Permission $permission)
    {
        $permission->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Permission activated successfully.'
        ]);
    }

    /**
     * Get all permissions grouped by module (API endpoint)
     */
    public function getGroupedPermissions()
    {
        $permissions = Permission::with('module')->get()->groupBy(function ($permission) {
            return $permission->module ? $permission->module->name : 'other';
        });

        return response()->json([
            'permissions' => $permissions
        ]);
    }

    /**
     * Bulk deactivate permissions
     */
    public function bulkDeactivate(Request $request)
    {
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
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate permissions that are assigned to roles: ' . $inUse->pluck('name')->join(', ')
            ], 400);
        }

        Permission::whereIn('id', $request->permission_ids)->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Selected permissions deactivated successfully.'
        ]);
    }

    /**
     * Remove the specified permission from storage
     */
    public function destroy(Permission $permission)
    {
        $currentUser = auth()->user();
        
        // Check if permission is in use
        if ($permission->roles()->count() > 0) {
            // Only allow super users to force delete permissions in use
            if (!$currentUser->isSuperUser()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete permission that is assigned to roles.'
                ], 400);
            }
            
            // For super users, log the action and proceed with deletion
            \Log::info('Super user force deleted permission', [
                'user_id' => $currentUser->id,
                'user_name' => $currentUser->name,
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'roles_count' => $permission->roles()->count(),
                'action' => 'force_delete_permission',
                'timestamp' => now()
            ]);
        }

        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permission deleted successfully.'
        ]);
    }
}
