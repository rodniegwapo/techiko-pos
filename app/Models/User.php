<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Traits\HasPermissions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, HasPermissions, Searchable;

    /**
     * The guard name for Spatie Permission.
     */
    protected $guard_name = 'web';

    /**
     * Role level constants
     */
    const ROLE_LEVELS = [
        1 => 'Super User',
        2 => 'Admin', 
        3 => 'Manager',
        4 => 'Staff',
        5 => 'Viewer'
    ];

    /**
     * The attributes that are not mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'remember_token',
        'email_verified_at',
    ];

    /**
     * Fields that can be searched using the Searchable trait
     */
    protected $searchable = [
        'name',
        'email',
        'roles.name'
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
     * Get the domain that this user belongs to.
     * Remove domain relationship - now using domain string column
     */
    // public function domain()
    // {
    //     return $this->belongsTo(Domain::class);
    // }

    // Add scope for easy domain filtering
    public function scopeForDomain($query, $domain) {
        return $query->where('domain', $domain);
    }

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
        return $this->is_super_user || $this->role_level === 1;
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

    /**
     * Check if user has permission to access a specific route
     */
    public function hasPermissionToRoute(string $routeName): bool
    {
        if ($this->isSuperUser()) {
            return true;
        }
        
        return $this->permissions()
            ->where('route_name', $routeName)
            ->orWhere('name', $routeName) // Fallback for backward compatibility
            ->exists();
    }

    /**
     * Get permissions by route name
     */
    public function getPermissionsByRoute(string $routeName)
    {
        return $this->permissions()
            ->where('route_name', $routeName)
            ->orWhere('name', $routeName) // Fallback for backward compatibility
            ->get();
    }

    /**
     * Role Level Methods
     */


    /**
     * Check if user is admin (level 2)
     */
    public function isAdmin(): bool
    {
        return $this->role_level === 2;
    }

    /**
     * Check if user is manager or above (level 3+)
     */
    public function isManagerOrAbove(): bool
    {
        return $this->role_level <= 3;
    }

    /**
     * Check if user can switch locations
     */
    public function canSwitchLocations(): bool
    {
        return $this->can_switch_locations || $this->role_level <= 2;
    }

    /**
     * Check if user has domain access (not super user)
     */
    public function hasDomainRestriction(): bool
    {
        return $this->role_level > 1;
    }

    /**
     * Check if user has location restriction
     */
    public function hasLocationRestriction(): bool
    {
        return $this->role_level >= 3;
    }

    /**
     * Get the role level name
     */
    public function getRoleLevelName(): string
    {
        return self::ROLE_LEVELS[$this->role_level] ?? 'Unknown';
    }

    /**
     * Get effective location ID based on role level
     */
    public function getEffectiveLocationId($requestLocationId = null)
    {
        // Super user and admin can switch locations
        if ($this->role_level <= 2) {
            return $requestLocationId ?? $this->location_id;
        }
        
        // Manager and below are restricted to their assigned location
        return $this->location_id;
    }

    /**
     * Get effective domain based on role level
     */
    public function getEffectiveDomain($requestDomain = null)
    {
        // Super user has no domain restriction
        if ($this->role_level === 1) {
            return $requestDomain ?? $this->domain;
        }
        
        // Admin and below are restricted to their domain
        return $this->domain;
    }
}
