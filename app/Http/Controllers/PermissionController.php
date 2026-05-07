<?php

namespace App\Http\Controllers;

use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Models\PermissionModule;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

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
            ->when($request->search, fn ($q, $s) => $q->search($s))
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

        $permissionModules = PermissionModule::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'display_name']);

        return Inertia::render('Permissions/Index', [
            'items' => PermissionResource::collection($permissions),
            'permissionsGrouped' => $permissionsGrouped,
            'permissionModules' => $permissionModules,
            'canCreate' => $currentUser->isSuperUser(),
            'canEdit' => $currentUser->isSuperUser(),
            'canDelete' => $currentUser->isSuperUser(),
            'isGlobalView' => true,
        ]);
    }

    /**
     * Normalize module slug and resolve or create a PermissionModule row.
     */
    private function resolvePermissionModule(Request $request): PermissionModule
    {
        $raw = (string) $request->input('module', '');
        $slug = Str::slug(trim($raw), '-');
        $slug = preg_replace('/-+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '' || strlen($slug) > 100) {
            throw ValidationException::withMessages([
                'module' => ['Enter a valid module name or slug (letters, numbers, hyphens).'],
            ]);
        }

        $displayName = $request->input('module_display_name');
        if (! is_string($displayName) || trim($displayName) === '') {
            $displayName = Str::headline(str_replace('-', ' ', $slug));
        } else {
            $displayName = trim($displayName);
        }

        return PermissionModule::firstOrCreate(
            ['name' => $slug],
            [
                'display_name' => $displayName,
                'icon' => null,
                'description' => null,
                'sort_order' => (int) (PermissionModule::query()->max('sort_order') ?? 0) + 1,
                'is_active' => true,
            ]
        );
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
            'module_display_name' => 'nullable|string|max:255',
            'action' => 'required|string|max:100',
        ]);

        $module = $this->resolvePermissionModule($request);

        $permission = Permission::create([
            'name' => $request->name,
            'route_name' => $request->route_name,
            'guard_name' => 'web',
            'module_id' => $module->id,
            'action' => $request->action,
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
            'name' => 'required|string|max:255|unique:permissions,name,'.$permission->id,
            'route_name' => 'required|string|max:255|unique:permissions,route_name,'.$permission->id,
            'description' => 'nullable|string|max:500',
            'module' => 'required|string|max:100',
            'module_display_name' => 'nullable|string|max:255',
            'action' => 'required|string|max:100',
        ]);

        $module = $this->resolvePermissionModule($request);

        $permission->update([
            'name' => $request->name,
            'route_name' => $request->route_name,
            'module_id' => $module->id,
            'action' => $request->action,
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
                'message' => 'Cannot deactivate permission that is assigned to roles.',
            ], 400);
        }

        $permission->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Permission deactivated successfully.',
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
            'message' => 'Permission activated successfully.',
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
            'permissions' => $permissions,
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
                'message' => 'Cannot deactivate permissions that are assigned to roles: '.$inUse->pluck('name')->join(', '),
            ], 400);
        }

        Permission::whereIn('id', $request->permission_ids)->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Selected permissions deactivated successfully.',
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
            if (! $currentUser->isSuperUser()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete permission that is assigned to roles.',
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
                'timestamp' => now(),
            ]);
        }

        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permission deleted successfully.',
        ]);
    }
}
