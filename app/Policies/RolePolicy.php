<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Auth\Access\Response;

class RolePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperUser() ||
            $user->can('roles.index') ||
            $user->can('roles.permission-matrix');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Role $role): bool
    {
        // Either explicit show, or general index access
        return $user->isSuperUser() ||
            $user->can('roles.show') ||
            $user->can('roles.index');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isSuperUser() ||
            $user->can('roles.create') ||
            $user->can('roles.store');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->isSuperUser() ||
            $user->can('roles.update') ||
            $user->can('roles.edit');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Role $role): bool
    {
        // Only super user or users with explicit destroy permission can delete
        if (!($user->isSuperUser() || $user->can('roles.destroy'))) {
            return false;
        }

        // Prevent deletion of system roles
        $systemRoles = ['manager', 'supervisor', 'cashier'];
        if (in_array(strtolower($role->name), $systemRoles)) {
            return false;
        }

        // Prevent deletion if role is assigned to users
        if ($role->users()->count() > 0) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Role $role): bool
    {
        return $user->isSuperUser();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Role $role): bool
    {
        return $user->isSuperUser();
    }
}
