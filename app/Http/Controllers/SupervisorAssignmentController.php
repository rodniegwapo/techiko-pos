<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserHierarchyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SupervisorAssignmentController extends Controller
{
    public function __construct()
    {
        // Super admin, admin, and manager can access supervisor assignment
        $this->middleware(['auth', 'role:super admin|admin|manager']);
    }

    /**
     * Assign a supervisor to a user (level-based)
     */
    public function assign(Request $request, User $user)
    {
        $currentUser = auth()->user();

        // Check if current user can assign supervisor to this user
        if (!UserHierarchyService::canAssignSupervisor($currentUser, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to assign supervisors to this user.'
            ], 403);
        }

        $validated = $request->validate([
            'supervisor_id' => [
                'required',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($user) {
                    $supervisor = User::find($value);
                    if (!$supervisor) {
                        $fail('Selected supervisor does not exist.');
                        return;
                    }

                    // Check if supervisor can supervise this user (level-based)
                    if (!UserHierarchyService::canAssignSupervisor($supervisor, $user)) {
                        $fail('Selected supervisor cannot supervise this user based on hierarchy levels.');
                        return;
                    }
                }
            ]
        ]);

        $oldSupervisorId = $user->supervisor_id;
        $user->update(['supervisor_id' => $validated['supervisor_id']]);

        // Log the assignment
        Log::info('Supervisor assignment (level-based)', [
            'assigner_id' => $currentUser->id,
            'assigner_name' => $currentUser->name,
            'assigner_role' => $currentUser->roles->first()?->name,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_role' => $user->roles->first()?->name,
            'old_supervisor_id' => $oldSupervisorId,
            'new_supervisor_id' => $validated['supervisor_id'],
            'supervisor_name' => User::find($validated['supervisor_id'])->name,
            'action' => 'assign_supervisor_level_based',
            'timestamp' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Supervisor assigned successfully',
            'user' => $user->fresh(['supervisor', 'roles'])
        ]);
    }

    /**
     * Remove supervisor assignment from a user (level-based)
     */
    public function remove(Request $request, User $user)
    {
        $currentUser = auth()->user();

        // Check if current user can manage this user
        if (!UserHierarchyService::canManageUser($currentUser, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to remove supervisor from this user.'
            ], 403);
        }

        $oldSupervisorId = $user->supervisor_id;
        $oldSupervisorName = $user->supervisor ? $user->supervisor->name : 'None';

        $user->update(['supervisor_id' => null]);

        // Log the removal
        Log::info('Supervisor assignment removed (level-based)', [
            'remover_id' => $currentUser->id,
            'remover_name' => $currentUser->name,
            'remover_role' => $currentUser->roles->first()?->name,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_role' => $user->roles->first()?->name,
            'removed_supervisor_id' => $oldSupervisorId,
            'removed_supervisor_name' => $oldSupervisorName,
            'action' => 'remove_supervisor_level_based',
            'timestamp' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Supervisor assignment removed successfully',
            'user' => $user->fresh(['supervisor', 'roles'])
        ]);
    }

    /**
     * Get available supervisors for assignment (level-based)
     */
    public function availableSupervisors(Request $request, User $user = null)
    {
        if ($user) {
            // Get supervisors that can supervise this specific user
            $supervisors = UserHierarchyService::getAssignableSupervisors($user);
        } else {
            // Check if role parameter is provided for role-based supervisor fetching
            if ($request->has('role')) {
                $roleName = $request->input('role');
                $role = \Spatie\Permission\Models\Role::where('name', $roleName)->first();

                if ($role) {
                    // If cascading mode is requested, only return immediate next level (role.level - 1)
                    $isCascading = $request->boolean('cascading', false) || $request->boolean('next', false);
                    if ($isCascading) {
                        $nextLevel = max(1, ($role->level ?? 0) - 1);
                        $supervisors = User::whereHas('roles', function ($q) use ($nextLevel) {
                            $q->where('level', $nextLevel);
                        })->with('roles')->select('id', 'name', 'email')->get();
                    } else {
                        // Create a temporary user with the specified role to get available supervisors
                        $tempUser = new User();
                        $tempUser->id = 'temp';
                        $tempUser->setRelation('roles', collect([$role]));
                        $supervisors = UserHierarchyService::getAssignableSupervisors($tempUser);
                    }
                } else {
                    $supervisors = [];
                }
            } else {
                // Get all users that can be supervisors (have lower level roles)
                $currentUser = auth()->user();
                $supervisors = UserHierarchyService::getSupervisableUsers($currentUser);
            }
        }

        return response()->json([
            'supervisors' => $supervisors
        ]);
    }

    /**
     * Auto-assign supervisors based on hierarchy levels
     */
    public function autoAssign()
    {
        $currentUser = auth()->user();

        // Only super users, super admin and admin can auto-assign
        if (!$currentUser->isSuperUser() && !$currentUser->hasRole(['super admin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators can perform auto-assignment.'
            ], 403);
        }

        $results = UserHierarchyService::autoAssignSupervisors();

        return redirect()->back();
    }

    /**
     * Get cascading assignment options for current user
     */
    public function cascadingOptions()
    {
        $currentUser = auth()->user();

        $options = UserHierarchyService::getCascadingAssignmentOptions($currentUser);

        return response()->json([
            'success' => true,
            'options' => $options,
            'user_level' => UserHierarchyService::getUserLevel($currentUser)
        ]);
    }

    /**
     * Perform cascading assignment
     */
    public function cascadingAssign(Request $request, User $supervisor)
    {
        $currentUser = auth()->user();

        // Check if current user can perform cascading assignment
        if (!UserHierarchyService::canCascadingAssign($currentUser, $supervisor)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot assign this user as your supervisor based on hierarchy levels.'
            ], 403);
        }

        $success = UserHierarchyService::performCascadingAssignment($currentUser, $supervisor);

        if ($success) {
            // Log the cascading assignment
            Log::info('Cascading supervisor assignment', [
                'assigner_id' => $currentUser->id,
                'assigner_name' => $currentUser->name,
                'assigner_role' => $currentUser->roles->first()?->name,
                'supervisor_id' => $supervisor->id,
                'supervisor_name' => $supervisor->name,
                'supervisor_role' => $supervisor->roles->first()?->name,
                'action' => 'cascading_assign_supervisor',
                'timestamp' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => "Successfully assigned {$supervisor->name} as your supervisor",
                'supervisor' => $supervisor->fresh(['roles'])
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to assign supervisor. Please try again.'
        ], 500);
    }

    /**
     * Get supervisor assignment history for a cashier
     */
    public function history(User $user)
    {
        // Load roles for authorization check
        $user->load('roles');
        $this->authorize('view', $user);

        // This would require a separate supervisor_assignment_logs table
        // For now, we'll return a simple response
        return response()->json([
            'message' => 'Assignment history feature coming soon',
            'current_supervisor' => $user->supervisor
        ]);
    }
}
