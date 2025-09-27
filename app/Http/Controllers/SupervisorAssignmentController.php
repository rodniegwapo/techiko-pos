<?php

namespace App\Http\Controllers;

use App\Models\User;
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
     * Assign a supervisor to a cashier
     */
    public function assign(Request $request, User $user)
    {
        // Load roles for authorization check
        $user->load('roles');
        $this->authorize('assignSupervisor', $user);

        $validated = $request->validate([
            'supervisor_id' => [
                'required',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $supervisor = User::find($value);
                    if (!$supervisor || !$supervisor->hasRole('supervisor')) {
                        $fail('Selected user must be a supervisor.');
                    }
                }
            ]
        ]);

        $oldSupervisorId = $user->supervisor_id;
        $user->update(['supervisor_id' => $validated['supervisor_id']]);
        
        // Log the assignment
        Log::info('Supervisor assignment', [
            'manager_id' => auth()->id(),
            'manager_name' => auth()->user()->name,
            'cashier_id' => $user->id,
            'cashier_name' => $user->name,
            'old_supervisor_id' => $oldSupervisorId,
            'new_supervisor_id' => $validated['supervisor_id'],
            'supervisor_name' => User::find($validated['supervisor_id'])->name,
            'action' => 'assign_supervisor',
            'timestamp' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Supervisor assigned successfully',
            'cashier' => $user->fresh(['supervisor'])
        ]);
    }

    /**
     * Remove supervisor assignment from a cashier
     */
    public function remove(Request $request, User $user)
    {
        // Load roles for authorization check
        $user->load('roles');
        $this->authorize('removeSupervisor', $user);

        $oldSupervisorId = $user->supervisor_id;
        $oldSupervisorName = $user->supervisor ? $user->supervisor->name : 'None';
        
        $user->update(['supervisor_id' => null]);

        // Log the removal
        Log::info('Supervisor assignment removed', [
            'manager_id' => auth()->id(),
            'manager_name' => auth()->user()->name,
            'cashier_id' => $user->id,
            'cashier_name' => $user->name,
            'removed_supervisor_id' => $oldSupervisorId,
            'removed_supervisor_name' => $oldSupervisorName,
            'action' => 'remove_supervisor',
            'timestamp' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Supervisor assignment removed successfully',
            'cashier' => $user->fresh(['supervisor'])
        ]);
    }

    /**
     * Get available supervisors for assignment
     */
    public function availableSupervisors()
    {
        $supervisors = User::whereHas('roles', function ($query) {
            $query->where('name', 'supervisor');
        })->select('id', 'name', 'email')->get();

        return response()->json([
            'supervisors' => $supervisors
        ]);
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
