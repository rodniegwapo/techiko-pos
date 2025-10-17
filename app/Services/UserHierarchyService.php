<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class UserHierarchyService
{
    /**
     * Get dynamic role hierarchy from database
     * Higher level (lower number) = more authority
     */
    public static function getRoleHierarchy(): array
    {
        $roles = Role::orderBy('level')->get();
        $hierarchy = [];
        
        foreach ($roles as $role) {
            $hierarchy[$role->name] = [
                'level' => $role->level,
                'label' => $role->name,
                'description' => $role->description,
                'can_manage' => static::getRoleManageableRoles($role),
                'can_view' => static::getRoleViewableRoles($role),
            ];
        }
        
        return $hierarchy;
    }
    
    /**
     * Get roles that a specific role can manage
     */
    private static function getRoleManageableRoles(Role $role): array
    {
        // Roles with higher level numbers (lower authority) can be managed
        return Role::where('level', '>', $role->level)
            ->pluck('name')
            ->toArray();
    }
    
    /**
     * Get roles that a specific role can view
     */
    private static function getRoleViewableRoles(Role $role): array
    {
        // Roles with same or higher level numbers can be viewed
        return Role::where('level', '>=', $role->level)
            ->pluck('name')
            ->toArray();
    }

    /**
     * Get roles that a user can view based on their role (dynamic)
     */
    public static function getViewableRoles(User $user): array
    {
        if ($user->isSuperUser()) {
            return Role::pluck('name')->toArray();
        }

        $userRole = $user->roles()->orderBy('level')->first();
        if (!$userRole) {
            return [];
        }

        // Roles with same or higher level numbers can be viewed
        return Role::where('level', '>=', $userRole->level)
            ->pluck('name')
            ->toArray();
    }

    /**
     * Get roles that a user can manage (dynamic)
     */
    public static function getManageableRoles(User $user): array
    {
        if ($user->isSuperUser()) {
            return Role::pluck('name')->toArray();
        }

        $userRole = $user->roles()->orderBy('level')->first();
        if (!$userRole) {
            return [];
        }

        // Roles with higher level numbers (lower authority) can be managed
        return Role::where('level', '>', $userRole->level)
            ->pluck('name')
            ->toArray();
    }

    /**
     * Check if a user can manage another user (dynamic)
     */
    public static function canManageUser(User $manager, User $subordinate): bool
    {
        if ($manager->isSuperUser()) {
            return true;
        }

        if ($manager->id === $subordinate->id) {
            return false;
        }

        $managerRole = $manager->roles()->orderBy('level')->first();
        $subordinateRole = $subordinate->roles()->orderBy('level')->first();

        if (!$managerRole || !$subordinateRole) {
            return false;
        }

        // Manager can manage users with higher level numbers (lower authority)
        return $subordinateRole->level > $managerRole->level;
    }

    /**
     * Check if a user can view another user (dynamic)
     */
    public static function canViewUser(User $viewer, User $viewee): bool
    {
        if ($viewer->isSuperUser()) {
            return true;
        }

        if ($viewer->id === $viewee->id) {
            return true;
        }

        $viewerRole = $viewer->roles()->orderBy('level')->first();
        $vieweeRole = $viewee->roles()->orderBy('level')->first();

        if (!$viewerRole || !$vieweeRole) {
            return false;
        }

        // Viewer can see users with same or higher level numbers
        return $vieweeRole->level >= $viewerRole->level;
    }

    /**
     * Get users under a manager's hierarchy (simplified)
     */
    public static function getUsersInHierarchy(User $manager): Collection
    {
        if ($manager->isSuperUser()) {
            return User::with('roles')->get();
        }

        $viewableRoles = static::getViewableRoles($manager);
        
        return User::whereHas('roles', function ($query) use ($viewableRoles) {
            $query->whereIn('name', $viewableRoles);
        })->with('roles')->get();
    }

    /**
     * Get the role level for a user (dynamic)
     */
    public static function getUserLevel(User $user): int
    {
        if ($user->isSuperUser()) {
            return 0; // Super user is above all
        }

        $userRole = $user->roles()->orderBy('level')->first();
        if (!$userRole) {
            return 999; // No role = lowest level
        }

        return $userRole->level;
    }

    /**
     * Get assignable supervisors for a user (level-based)
     */
    public static function getAssignableSupervisors(User $user): Collection
    {
        // Handle temporary users (for role-based queries)
        if ($user->relationLoaded('roles') && !$user->exists) {
            $userRole = $user->roles->first();
        } else {
            $userRole = $user->roles()->orderBy('level')->first();
        }
        
        if (!$userRole) {
            return collect();
        }

        // Find roles that can supervise this user (roles with lower level numbers)
        $supervisorRoles = Role::where('level', '<', $userRole->level)
            ->pluck('name')
            ->toArray();

        if (empty($supervisorRoles)) {
            return collect();
        }

        return User::whereHas('roles', function ($query) use ($supervisorRoles) {
            $query->whereIn('name', $supervisorRoles);
        })->with('roles')->select('id', 'name', 'email')->get();
    }

    /**
     * Get users that a role can supervise (level-based)
     */
    public static function getSupervisableUsers(User $supervisor): Collection
    {
        $supervisorRole = $supervisor->roles()->orderBy('level')->first();
        if (!$supervisorRole) {
            return collect();
        }

        // Find roles that this supervisor can manage (roles with higher level numbers)
        $supervisableRoles = Role::where('level', '>', $supervisorRole->level)
            ->pluck('name')
            ->toArray();

        return User::whereHas('roles', function ($query) use ($supervisableRoles) {
            $query->whereIn('name', $supervisableRoles);
        })->select('id', 'name', 'email')->get();
    }

    /**
     * Check if a user can assign supervisor to another user (level-based)
     */
    public static function canAssignSupervisor(User $assigner, User $assignee): bool
    {
        $assignerRole = $assigner->roles()->orderBy('level')->first();
        $assigneeRole = $assignee->roles()->orderBy('level')->first();

        if (!$assignerRole || !$assigneeRole) {
            return false;
        }

        // Assigner must have lower level number (higher authority) than assignee
        return $assignerRole->level < $assigneeRole->level;
    }

    /**
     * Get the best supervisor for a user based on hierarchy levels
     */
    public static function getBestSupervisor(User $user): ?User
    {
        $userRole = $user->roles()->orderBy('level')->first();
        if (!$userRole) {
            return null;
        }

        // Super users should not have supervisors
        if ($user->isSuperUser()) {
            return null;
        }

        // Find the role with the immediate next higher authority (lower level number)
        // Hierarchy: Level 1=Super Admin, 2=Admin, 3=Manager, 4=Supervisor, 5=Cashier
        // For example: Cashier (level 5) -> Supervisor (level 4), Supervisor (level 4) -> Manager (level 3)
        $supervisorLevel = $userRole->level - 1;
        
        // If user is at level 2 (admin), they should report to Super Admin (level 1)
        if ($supervisorLevel < 1) {
            return null; // No supervisor available
        }

        $supervisorRole = Role::where('level', $supervisorLevel)->first();

        if (!$supervisorRole) {
            return null;
        }

        // Find users with that role who don't have too many subordinates
        // Prioritize users with fewer subordinates for load balancing
        return User::whereHas('roles', function ($query) use ($supervisorRole) {
            $query->where('name', $supervisorRole->name);
        })
        ->where('id', '!=', $user->id) // Don't assign user as their own supervisor
        ->where('is_super_user', false) // Don't assign super users as supervisors
        ->withCount('subordinates')
        ->orderBy('subordinates_count', 'asc')
        ->first();
    }

    /**
     * Auto-assign supervisors based on hierarchy levels
     */
    public static function autoAssignSupervisors(): array
    {
        $results = [
            'assigned' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => []
        ];

        // Get all users without supervisors, excluding super users
        $usersWithoutSupervisors = User::whereNull('supervisor_id')
            ->where('is_super_user', false)
            ->with('roles')
            ->get();

        foreach ($usersWithoutSupervisors as $user) {
            try {
                $userRole = $user->roles()->orderBy('level')->first();
                $userRoleName = $userRole ? $userRole->name : 'No Role';
                
                $bestSupervisor = static::getBestSupervisor($user);
                
                // Special case: If user is admin (level 2) and no supervisor found, assign to Super Admin
                if (!$bestSupervisor && $userRole && $userRole->level === 2) {
                    $superAdmin = User::where('is_super_user', true)->first();
                    if ($superAdmin) {
                        $bestSupervisor = $superAdmin;
                    }
                }
                
                if ($bestSupervisor && $bestSupervisor->id !== $user->id) {
                    $supervisorRole = $bestSupervisor->roles()->orderBy('level')->first();
                    $supervisorRoleName = $supervisorRole ? $supervisorRole->name : ($bestSupervisor->is_super_user ? 'Super User' : 'No Role');
                    
                    $user->update(['supervisor_id' => $bestSupervisor->id]);
                    $results['assigned']++;
                    $results['details'][] = "✅ Assigned {$user->name} ({$userRoleName}) to {$bestSupervisor->name} ({$supervisorRoleName})";
                } else {
                    $results['skipped']++;
                    if ($user->isSuperUser()) {
                        $results['details'][] = "⏭️ Skipped {$user->name} - Super users don't need supervisors";
                    } else {
                        $results['details'][] = "⚠️ No suitable supervisor found for {$user->name} ({$userRoleName}) - Check role hierarchy";
                    }
                }
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][] = "❌ Error assigning supervisor for {$user->name}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Get role hierarchy information for debugging
     */
    public static function getRoleHierarchyInfo(): array
    {
        $roles = Role::orderBy('level')->get();
        $info = [];
        
        foreach ($roles as $role) {
            $info[] = [
                'name' => $role->name,
                'level' => $role->level,
                'description' => $role->description,
                'user_count' => $role->users()->count()
            ];
        }
        
        return $info;
    }

    /**
     * Get users that a specific user can manage (cascading assignment)
     * Level 5 can see Level 4, Level 4 can see Level 3, etc.
     */
    public static function getCascadingManageableUsers(User $user): Collection
    {
        $userRole = $user->roles()->orderBy('level')->first();
        if (!$userRole) {
            return collect();
        }

        // Get the immediate next level up (lower number = higher authority)
        $nextLevel = $userRole->level - 1;
        
        if ($nextLevel < 1) {
            return collect(); // No higher level available
        }

        // Find users with the next level up
        return User::whereHas('roles', function ($query) use ($nextLevel) {
            $query->where('level', $nextLevel);
        })->with('roles')->get();
    }

    /**
     * Get users that a specific user can assign to (cascading assignment)
     * Level 5 can assign Level 4, Level 4 can assign Level 3, etc.
     */
    public static function getCascadingAssignableUsers(User $user): Collection
    {
        $userRole = $user->roles()->orderBy('level')->first();
        if (!$userRole) {
            return collect();
        }

        // Get the immediate next level up (lower number = higher authority)
        $nextLevel = $userRole->level - 1;
        
        if ($nextLevel < 1) {
            return collect(); // No higher level available
        }

        // Find users with the next level up who don't have supervisors
        return User::whereHas('roles', function ($query) use ($nextLevel) {
            $query->where('level', $nextLevel);
        })
        ->whereNull('supervisor_id')
        ->with('roles')
        ->get();
    }

    /**
     * Check if a user can assign another user as supervisor (cascading)
     */
    public static function canCascadingAssign(User $assigner, User $assignee): bool
    {
        $assignerRole = $assigner->roles()->orderBy('level')->first();
        $assigneeRole = $assignee->roles()->orderBy('level')->first();

        if (!$assignerRole || !$assigneeRole) {
            return false;
        }

        // Assigner can assign users from the immediate next level up
        return $assigneeRole->level === ($assignerRole->level - 1);
    }

    /**
     * Get cascading assignment options for a user
     */
    public static function getCascadingAssignmentOptions(User $user): array
    {
        $userRole = $user->roles()->orderBy('level')->first();
        if (!$userRole) {
            return [];
        }

        $options = [];
        
        // Get users from the next level up that can be assigned
        $assignableUsers = static::getCascadingAssignableUsers($user);
        
        if ($assignableUsers->isNotEmpty()) {
            $nextLevelRole = Role::where('level', $userRole->level - 1)->first();
            $options[] = [
                'level' => $userRole->level - 1,
                'role_name' => $nextLevelRole?->name ?? 'Unknown',
                'users' => $assignableUsers,
                'action' => 'assign_supervisor',
                'description' => "Assign {$nextLevelRole?->name} as your supervisor"
            ];
        }

        return $options;
    }

    /**
     * Perform cascading assignment
     */
    public static function performCascadingAssignment(User $assigner, User $assignee): bool
    {
        if (!static::canCascadingAssign($assigner, $assignee)) {
            return false;
        }

        try {
            // Assign the higher level user as supervisor
            $assigner->update(['supervisor_id' => $assignee->id]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

