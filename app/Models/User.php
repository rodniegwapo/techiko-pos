<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Traits\HasPermissions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, HasPermissions;

    /**
     * The guard name for Spatie Permission.
     */
    protected $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'supervisor_id',
        'is_super_user',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_super_user' => 'boolean',
    ];

    /**
     * Get the supervisor that supervises this user.
     */
    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /**
     * Get the users that this user supervises.
     */
    public function subordinates()
    {
        return $this->hasMany(User::class, 'supervisor_id');
    }

    /**
     * Check if this user can supervise the given user.
     */
    public function canSupervise(User $user): bool
    {
        // Only supervisors can supervise cashiers
        return $this->hasRole('supervisor') && $user->hasRole('cashier');
    }

    /**
     * Check if this user is supervised by the given user.
     */
    public function isSupervisedBy(User $supervisor): bool
    {
        return $this->supervisor_id === $supervisor->id;
    }

    /**
     * Check if this user is a super user.
     */
    public function isSuperUser(): bool
    {
        return $this->is_super_user;
    }


    /**
     * Check if user has any of the specified permissions.
     */
    public function hasAnyPermission($permissions, string $guard = null): bool
    {
        // Super users have all permissions
        if ($this->isSuperUser()) {
            return true;
        }

        $permissions = is_array($permissions) ? $permissions : [$permissions];
        
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission, $guard)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this user can manage another user based on hierarchy
     */
    public function canManageUser(User $user): bool
    {
        return \App\Services\UserHierarchyService::canManageUser($this, $user);
    }

    /**
     * Check if this user can view another user based on hierarchy
     */
    public function canViewUser(User $user): bool
    {
        return \App\Services\UserHierarchyService::canViewUser($this, $user);
    }

    /**
     * Get all users this user can manage
     */
    public function getManagedUsers()
    {
        return \App\Services\UserHierarchyService::getUsersInHierarchy($this);
    }

    /**
     * Get the hierarchy level of this user
     */
    public function getHierarchyLevel(): int
    {
        return \App\Services\UserHierarchyService::getUserLevel($this);
    }

    /**
     * Get all subordinates (recursively)
     */
    public function getAllSubordinates()
    {
        return $this->subordinates()->with('subordinates')->get()->flatMap(function ($subordinate) {
            return collect([$subordinate])->merge($subordinate->getAllSubordinates());
        });
    }
}
