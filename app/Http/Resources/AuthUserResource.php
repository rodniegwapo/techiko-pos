<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profileUrl = "https://ui-avatars.com/api/?name={$this->name}&background=287e47&color=ffff";

        return array_merge(parent::toArray($request), [
            'email' => $this->email,
            'profileUrl' => $profileUrl,
            'name' => $this->name,
            'roles' => $this->roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                ];
            }),
            'permissions' => $this->getAllPermissions()->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                ];
            }),
        ]);
    }
}
