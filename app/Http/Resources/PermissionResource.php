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
        return [
            'id' => $this->id,
            'name' => $this->name, // Action display name: "View", "Create", "Edit"
            'route_name' => $this->route_name, // Technical route name: "users.index"
            'action' => $this->action, // Action: "index", "create", "edit"
            'module_id' => $this->module_id,
            'module' => $this->whenLoaded('module', function () {
                return [
                    'id' => $this->module->id,
                    'name' => $this->module->name,
                    'display_name' => $this->module->display_name,
                    'icon' => $this->module->icon,
                ];
            }),
            'full_display_name' => $this->full_display_name, // "Users - View", "Products - Create"
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

