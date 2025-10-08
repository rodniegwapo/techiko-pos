<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $nameParts = explode('.', $this->name);
        $module = $nameParts[0] ?? '';
        $action = $nameParts[1] ?? '';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'module' => $module,
            'action' => $action,
            'guard_name' => $this->guard_name,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'roles_count' => $this->when(isset($this->roles_count), $this->roles_count),
            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'users_count' => $role->users_count ?? 0,
                    ];
                });
            }),
        ];
    }
}

