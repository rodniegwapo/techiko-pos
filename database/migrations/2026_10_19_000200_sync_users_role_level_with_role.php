<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * users.role_level defaulted to 3 for staff made in the app and never followed the role, so an
 * admin counted as a manager and a cashier passed manager checks. Set it from each user's role
 * (lowest level). Never 1 for a normal account: that would make it a super user.
 */
return new class extends Migration
{
    public function up(): void
    {
        $levels = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->groupBy('model_has_roles.model_id')
            ->selectRaw('model_has_roles.model_id AS user_id, MIN(roles.level) AS level')
            ->pluck('level', 'user_id');

        foreach (DB::table('users')->whereIn('id', $levels->keys())->get(['id', 'is_super_user']) as $user) {
            $level = (int) $levels[$user->id];
            DB::table('users')->where('id', $user->id)->update([
                'role_level' => $user->is_super_user ? $level : max(2, $level),
            ]);
        }
    }

    public function down(): void
    {
        // Leaves the synced levels; the old values were defaults, not choices.
    }
};
