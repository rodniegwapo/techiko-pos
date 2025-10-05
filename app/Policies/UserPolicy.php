<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Log;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Super users have all permissions
        if ($user->isSuperUser()) {
            return true;
        }
        
        return $user->hasAnyRole(['super admin', 'admin', 'manager', 'supervisor']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        // Super users can view anyone
        if ($user->isSuperUser()) {
            return true;
        }
        
        if ($user->hasAnyRole(['super admin', 'admin'])) {
            return true;
        }
        
        // Manager can only view cashiers and supervisors
        if ($user->hasRole('manager')) {
            return $model->hasAnyRole(['cashier', 'supervisor']);
        }
        
        // Supervisor can only view cashiers assigned to them
        if ($user->hasRole('supervisor')) {
            // Ensure both users have roles loaded
            if (!$user->relationLoaded('roles')) {
                $user->load('roles');
            }
            if (!$model->relationLoaded('roles')) {
                $model->load('roles');
            }
            
            // Supervisor can only view cashiers that are specifically assigned to them
            return $model->hasRole('cashier') && 
                   $model->supervisor_id !== null && 
                   $model->supervisor_id === $user->id;
        }
        
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Super users can create anyone
        if ($user->isSuperUser()) {
            return true;
        }
        
        // Super Admin, Admin, and Manager can create users
        // Managers can only create cashiers and supervisors
        return $user->hasAnyRole(['super admin', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        // Super users can edit anyone
        if ($user->isSuperUser()) {
            return true;
        }
        
        // super admin can edit anyone
        if ($user->hasRole('super admin')) {
            return true;
        }

        // admin can edit users except super admins
        if ($user->hasRole('admin')) {
            return !$model->hasRole('super admin');
        }

        // managers can edit non-sensitive fields of cashiers and supervisors only
        if ($user->hasRole('manager')) {
            return $model->hasAnyRole(['cashier', 'supervisor']);
        }

        return false;
    }

    /**
     * Determine whether the user can update sensitive fields (password, email, name, roles).
     */
    public function updateSensitiveFields(User $user, User $model): bool
    {
        // Super users can update sensitive fields for anyone
        if ($user->isSuperUser()) {
            return true;
        }
        
        // Only Super Admin and Admin can update sensitive fields
        if ($user->hasRole('super admin')) {
            return true;
        }

        if ($user->hasRole('admin')) {
            return !$model->hasRole('super admin');
        }

        // Managers cannot update sensitive fields
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        // Super users can delete anyone (except themselves)
        if ($user->isSuperUser()) {
            return $user->id !== $model->id;
        }
        
        // Only super admin can delete users
        if (!$user->hasRole('super admin')) {
            return false;
        }

        // Cannot delete yourself
        if ($user->id === $model->id) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $user->isSuperUser() || $user->hasRole('super admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $user->isSuperUser() || $user->hasRole('super admin');
    }

    /**
     * Determine whether the user can assign supervisors to other users.
     */
    public function assignSupervisor(User $user, User $model): bool
    {
        // Super users can assign supervisors to anyone
        if ($user->isSuperUser()) {
            return true;
        }
        
        // Super Admin and Admin can assign supervisors to anyone
        if ($user->hasAnyRole(['super admin', 'admin'])) {
            return true;
        }

        // Manager can assign supervisors to cashiers only
        if ($user->hasRole('manager')) {
            return $model->hasRole('cashier');
        }

        return false;
    }

    /**
     * Determine whether the user can remove supervisor assignments.
     */
    public function removeSupervisor(User $user, User $model): bool
    {
        // Same permissions as assign supervisor
        return $this->assignSupervisor($user, $model);
    }
}