<?php

namespace App\Http\Controllers\Domains;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Domain;
use App\Models\User;
use App\Services\UserHierarchyService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {
        // Middleware is handled at route level
    }

    /**
     * Display a listing of users for the domain.
     */
    public function index(Request $request, Domain $domain = null)
    {
        $currentUser = auth()->user();

        $users = User::query()
            ->with(['roles', 'supervisor'])
            ->where('domain', $domain->name_slug)
            ->when($request->search, fn($q, $s) => $q->search($s))
            ->when($request->role, function ($query, $role) {
                return $query->whereHas('roles', function ($q) use ($role) {
                    $q->where('name', $role);
                });
            })
            ->latest()
            ->paginate(15);

        $roles = $this->userService->getManageableRoles($currentUser);

        return Inertia::render('Users/Index', [
            'items' => UserResource::collection($users),
            'roles' => $roles,
            'hierarchy' => UserHierarchyService::getRoleHierarchy(),
            'currentDomain' => $domain,
            'isGlobalView' => !$domain,
        ]);
    }

    /**
     * Store a newly created user for the domain.
     */
    public function store(Request $request, Domain $domain = null)
    {
        $this->authorize('create', User::class);

        $currentUser = auth()->user();
        $rules = $this->userService->getCreationValidationRules($currentUser);
        $validated = $request->validate($rules);

        if ($domain) {
            $validated['domain'] = $domain->name_slug;
        }
        $user = $this->userService->createUser($validated, $currentUser);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'user' => new UserResource($user->load(['roles', 'supervisor']))
        ], 201);
    }

    /**
     * Update the specified user for the domain.
     */
    public function update(Request $request, Domain $domain, User $user)
    {
        // Ensure user belongs to this domain
        if ($user->domain !== $domain->name_slug) {
            abort(403, 'User does not belong to this domain');
        }

        $this->authorize('update', $user);

        $currentUser = auth()->user();
        $rules = $this->userService->getUpdateValidationRules($currentUser, $user);
        $validated = $request->validate($rules);

        $user = $this->userService->updateUser($user, $validated, $currentUser);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => new UserResource($user->load(['roles', 'supervisor']))
        ]);
    }

    /**
     * Remove the specified user from the domain.
     */
    public function destroy(Domain $domain, User $user)
    {
        // Ensure user belongs to this domain
        if ($user->domain !== $domain->name_slug) {
            abort(403, 'User does not belong to this domain');
        }

        $this->authorize('delete', $user);

        // Prevent deleting the current user
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account'
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Display user hierarchy for the domain.
     */
    public function hierarchy(Request $request, Domain $domain = null)
    {
        $currentUser = auth()->user();

        // For domain context, show all users in the domain
        if ($domain) {
            $users = User::with(['roles', 'supervisor', 'subordinates'])
                ->where('domain', $domain->name_slug)
                ->orderBy('name')
                ->get()
                ->toArray();
        } else {
            // For global context, use the hierarchy-based approach
            $users = $this->userService->getHierarchyUsers($currentUser);
        }

        $hierarchy = UserHierarchyService::getRoleHierarchy();

        return Inertia::render('Users/Hierarchy', [
            'users' => $users,
            'hierarchy' => $hierarchy,
            'currentDomain' => $domain,
            'isGlobalView' => !$domain,
        ]);
    }

    /**
     * Auto-assign supervisors for the domain.
     */
    public function autoAssignSupervisors(Request $request, Domain $domain = null)
    {
        $currentUser = auth()->user();

        // Only super users, super admin and admin can auto-assign
        if (!$currentUser->isSuperUser() && !$currentUser->hasRole(['super admin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators can perform auto-assignment.'
            ], 403);
        }

        // Get users filtered by domain for auto-assignment
        $users = User::whereNull('supervisor_id')
            ->where('is_super_user', false)
            ->when($domain, function ($query) use ($domain) {
                return $query->where('domain', $domain->name_slug);
            })
            ->with('roles')
            ->get();

        $results = [
            'assigned' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => []
        ];

        foreach ($users as $user) {
            try {
                $userRole = $user->roles()->orderBy('level')->first();
                $userRoleName = $userRole ? $userRole->name : 'No Role';

                $bestSupervisor = UserHierarchyService::getBestSupervisor($user);

                // Special case: If user is admin (level 2) and no supervisor found in domain, 
                // assign to Super Admin (super users can supervise across domains)
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

        $message = "Auto-assignment completed: ";
        $message .= "{$results['assigned']} assigned, ";
        $message .= "{$results['skipped']} skipped, ";
        $message .= "{$results['errors']} errors.";

        return redirect()->back()->with('success', $message);
    }

    /**
     * Get available supervisors for the domain.
     */
    public function availableSupervisors(Request $request, Domain $domain, User $user = null)
    {
        $currentUser = auth()->user();
        $isSuperUser = $currentUser->is_super_user;

        if ($user) {
            // Ensure user belongs to this domain (unless current user is super user)
            if (!$isSuperUser && $user->domain !== $domain->name_slug) {
                abort(403, 'User does not belong to this domain');
            }

            // Get supervisors that can supervise this specific user
            $supervisors = UserHierarchyService::getAssignableSupervisors($user);

            // Filter by domain only if current user is not super user
            if (!$isSuperUser) {
                $supervisors = $supervisors->filter(function ($supervisor) use ($domain) {
                    return $supervisor['domain'] === $domain->name_slug;
                });
            }
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
                        $query = User::whereHas('roles', function ($q) use ($nextLevel) {
                            $q->where('level', $nextLevel);
                        });

                        // Only filter by domain if not super user
                        if (!$isSuperUser) {
                            $query->where('domain', $domain->name_slug);
                        }

                        $supervisors = $query->with('roles')
                            ->select('id', 'name', 'email')
                            ->get();
                    } else {
                        // Create a temporary user with the specified role to get available supervisors
                        $tempUser = new User();
                        $tempUser->id = 'temp';
                        $tempUser->domain = $domain->name_slug;
                        $tempUser->setRelation('roles', collect([$role]));
                        $supervisors = UserHierarchyService::getAssignableSupervisors($tempUser);

                        // Filter by domain only if current user is not super user
                        if (!$isSuperUser) {
                            $supervisors = $supervisors->filter(function ($supervisor) use ($domain) {
                                return $supervisor['domain'] === $domain->name_slug;
                            });
                        }
                    }
                } else {
                    $supervisors = collect([]);
                }
            } else {
                // Get all users that can be supervisors (have lower level roles)
                $supervisors = UserHierarchyService::getSupervisableUsers($currentUser);

                // Filter by domain only if current user is not super user
                if (!$isSuperUser) {
                    $supervisors = $supervisors->filter(function ($supervisor) use ($domain) {
                        return $supervisor['domain'] === $domain->name_slug;
                    });
                }
            }
        }

        return response()->json([
            'supervisors' => $supervisors
        ]);
    }

    /**
     * Get available supervisors for a specific user in the domain.
     */
    public function availableSupervisorsForUser(Request $request, Domain $domain, User $user)
    {
        // Ensure user belongs to this domain
        if ($user->domain !== $domain->name_slug) {
            abort(403, 'User does not belong to this domain');
        }

        // Get supervisors that can supervise this user, filtered by domain
        $supervisors = UserHierarchyService::getAssignableSupervisors($user);

        // Filter by domain if not super user
        $currentUser = auth()->user();
        if (!$currentUser->is_super_user) {
            $supervisors = $supervisors->filter(function ($supervisor) use ($domain) {
                return $supervisor['domain'] === $domain->name_slug;
            });
        }

        return response()->json(['supervisors' => $supervisors]);
    }

    /**
     * Assign a supervisor to a user in the domain.
     */
    public function assignSupervisor(Request $request, Domain $domain, User $user)
    {
        $request->validate([
            'supervisor_id' => 'required|exists:users,id'
        ]);

        // Check if user belongs to domain
        if ($user->domain !== $domain->name_slug) {
            abort(403, 'User does not belong to this domain');
        }

        $supervisor = User::findOrFail($request->supervisor_id);

        // Check if supervisor belongs to same domain (unless current user is super user)
        $currentUser = auth()->user();
        if (!$currentUser->is_super_user && $supervisor->domain !== $domain->name_slug) {
            abort(403, 'Supervisor must be from the same domain');
        }

        // Check if supervisor can supervise this user
        $assignableSupervisors = UserHierarchyService::getAssignableSupervisors($user);
        if (!$assignableSupervisors->contains('id', $supervisor->id)) {
            abort(403, 'This supervisor cannot supervise this user');
        }

        $user->update(['supervisor_id' => $supervisor->id]);

        return response()->json([
            'success' => true,
            'message' => 'Supervisor assigned successfully'
        ]);
    }
}
